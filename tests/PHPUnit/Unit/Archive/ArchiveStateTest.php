<?php
declare(strict_types=1);

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Unit\Archive;

use PHPUnit\Framework\TestCase;
use Piwik\Archive\ArchiveInvalidator;
use Piwik\Archive\ArchiveState;
use Piwik\Archive\DataCollection;
use Piwik\DataAccess\ArchiveWriter;
use Piwik\DataTable;
use Piwik\Period;
use Piwik\Segment;
use Piwik\Site;

/**
 * @group Archive
 * @group ArchiveStateTest
 */
class ArchiveStateTest extends TestCase
{
    private const IDSITE = 1;

    /**
     * @dataProvider getArchiveStateTestData
     */
    public function testArchiveState(
        string $date1,
        string $date2,
        string $tsArchived,
        int $archiveState,
        array $daysWithRememberedInvalidations,
        string $expectedMetadataState
    ): void {
        $this->setUpSite([]);

        [$archiveData, $archiveIds, $archiveStates] = $this->createArchiveInfo(
            $date1,
            $date2,
            $tsArchived,
            $archiveState
        );

        $collection = $this->createCollection($archiveData);

        $archiveInvalidator = self::createMock(ArchiveInvalidator::class);
        $archiveInvalidator
            ->method('getDaysWithRememberedInvalidationsForSite')
            ->willReturn($daysWithRememberedInvalidations);

        $archiveState = new ArchiveState($archiveInvalidator);
        $archiveState->addMetadataToResultCollection($collection, $archiveData, $archiveIds, $archiveStates);

        $this->assertMetadataState($expectedMetadataState, $collection);
    }

    public function getArchiveStateTestData(): iterable
    {
        yield 'complete' => [
            '2020-01-31',
            '2020-01-31',
            '2020-02-01 10:00:00',
            ArchiveWriter::DONE_OK,
            [],
            ArchiveState::COMPLETE
        ];

        yield 'day in range remembered as invalidated' => [
            '2020-01-01',
            '2020-01-31',
            '2020-02-01 10:00:00',
            ArchiveWriter::DONE_OK,
            ['2020-01-16'],
            ArchiveState::INCOMPLETE
        ];

        yield 'date outside range remembered as invalidated' => [
            '2020-01-01',
            '2020-01-31',
            '2020-02-01 10:00:00',
            ArchiveWriter::DONE_OK,
            ['2020-03-16'],
            ArchiveState::COMPLETE
        ];

        yield 'invalidated archive' => [
            '2020-01-31',
            '2020-01-31',
            '2020-02-01 10:00:00',
            ArchiveWriter::DONE_INVALIDATED,
            [],
            ArchiveState::INVALIDATED
        ];
    }

    /**
     * @dataProvider getCheckTsArchivedWithBorderTimezonesTestData
     */
    public function testCheckTsArchivedWithBorderTimezones(
        string $timezone,
        string $date,
        string $tsArchivedUTC,
        string $expectedState
    ): void {
        $this->setUpSite(['timezone' => $timezone]);

        $archiveInvalidator = self::createMock(ArchiveInvalidator::class);
        $archiveInvalidator->method('getDaysWithRememberedInvalidationsForSite')->willReturn([]);

        [$archiveData, $archiveIds, $archiveStates] = $this->createArchiveInfo(
            $date,
            $date,
            $tsArchivedUTC,
            ArchiveWriter::DONE_OK
        );

        $collection = $this->createCollection($archiveData);

        $archiveState = new ArchiveState($archiveInvalidator);
        $archiveState->addMetadataToResultCollection($collection, $archiveData, $archiveIds, $archiveStates);

        $this->assertMetadataState($expectedState, $collection);
    }

    public function getCheckTsArchivedWithBorderTimezonesTestData(): iterable
    {
        yield 'UTC+14, complete' => [
            'UTC+14',
            '2020-10-10',
            '2020-10-10 12:00:00',
            ArchiveState::COMPLETE,
        ];

        yield 'UTC+14, incomplete' => [
            'UTC+14',
            '2020-10-10',
            '2020-10-10 02:00:00',
            ArchiveState::INCOMPLETE,
        ];

        yield 'UTC-12, complete' => [
            'UTC-12',
            '2020-10-10',
            '2020-10-11 16:00:00',
            ArchiveState::COMPLETE,
        ];

        yield 'UTC-12, incomplete' => [
            'UTC-12',
            '2020-10-10',
            '2020-10-11 02:00:00',
            ArchiveState::INCOMPLETE,
        ];
    }

    public function testNoMetadataSetIfNoInformationAvailable(): void
    {
        $this->setUpSite([]);

        $archiveInvalidator = self::createMock(ArchiveInvalidator::class);
        $archiveInvalidator->method('getDaysWithRememberedInvalidationsForSite')->willReturn([]);

        [$archiveData, $archiveIds] = $this->createArchiveInfo(
            '2020-01-31',
            '2020-01-31',
            '2020-02-01 10:00:00',
            ArchiveWriter::DONE_INVALIDATED
        );

        $collection = $this->createCollection($archiveData);

        $archiveState = new ArchiveState($archiveInvalidator);
        $archiveState->addMetadataToResultCollection($collection, $archiveData, $archiveIds, []);

        $this->assertMetadataState(null, $collection);
    }

    public function testRememberedInvalidationSetsMissingArchiveIncomplete(): void
    {
        $this->setUpSite([]);

        $archiveInvalidator = self::createMock(ArchiveInvalidator::class);
        $archiveInvalidator->method('getDaysWithRememberedInvalidationsForSite')->willReturn(['2020-01-31']);

        [$archiveData, $archiveIds] = $this->createArchiveInfo(
            '2020-01-31',
            '2020-01-31',
            '2020-02-01 10:00:00',
            ArchiveWriter::DONE_OK
        );

        $collection = $this->createCollection($archiveData);

        $archiveState = new ArchiveState($archiveInvalidator);
        $archiveState->addMetadataToResultCollection($collection, $archiveData, $archiveIds, []);

        $this->assertMetadataState(ArchiveState::INCOMPLETE, $collection);
    }

    private function assertMetadataState(?string $expectedState, DataCollection $collection): void
    {
        $data = $collection->getIndexedArray([]);
        $metadata = $collection->getDataRowMetadata($data);

        if (null === $expectedState) {
            self::assertArrayNotHasKey(DataTable::ARCHIVE_STATE_METADATA_NAME, $metadata);
        } else {
            self::assertSame($expectedState, $metadata[DataTable::ARCHIVE_STATE_METADATA_NAME]);
        }
    }

    private function createArchiveInfo(
        string $date1,
        string $date2,
        string $tsArchived,
        int $archiveState
    ): array {
        $archiveId = 15;
        $rangeStr = $date1 . ',' . $date2;

        $archiveData = [
            [
                'idsite' => self::IDSITE,
                'date1' => $date1,
                'date2' => $date2,
                'name' => 'nb_visits',
                'value' => 123,
                'ts_archived' => $tsArchived,
            ],
        ];

        $archiveIds = [$rangeStr => [$archiveId]];
        $archiveStates = [self::IDSITE => [$rangeStr => [$archiveId => $archiveState]]];

        return [$archiveData, $archiveIds, $archiveStates];
    }

    private function createCollection($archiveData): DataCollection
    {
        $periods = [];

        foreach ($archiveData as $row) {
            if ($row['date1'] === $row['date2']) {
                $periods[] = Period\Factory::build('day', $row['date1']);
            } else {
                $periods[] = Period\Factory::build('range', $row['date1'] . ',' . $row['date2']);
            }
        }

        $collection = new DataCollection(
            ['nb_visits'],
            'numeric',
            [1],
            $periods,
            $this->createMockSegment(),
            ['nb_visits' => -1]
        );

        foreach ($archiveData as $row) {
            $collection->set(
                self::IDSITE,
                $row['date1'] . ',' . $row['date2'],
                $row['name'],
                $row['value'],
                [
                    DataTable::ARCHIVED_DATE_METADATA_NAME => $row['ts_archived'],
                ]
            );
        }

        return $collection;
    }

    private function createMockSegment(): Segment
    {
        // using mock since Segment makes API queries
        $segment = $this->getMockBuilder(Segment::class)->disableOriginalConstructor()->getMock();
        $segment->method('getString')->willReturn('');

        return $segment;
    }

    private function setUpSite(array $siteInfo): void
    {
        $defaults = [
            'idsite' => self::IDSITE,
            'ecommerce' => 0,
            'sitesearch' => 0,
            'exclude_unknown_urls' => 0,
            'keep_url_fragment' => 0,
            'timezone' => 'UTC',
        ];

        // setting static site information since Site makes API queries
        Site::setSiteFromArray(self::IDSITE, array_merge($defaults, $siteInfo));
    }
}
