<?php

declare(strict_types=1);

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Archive;

use Piwik\Container\StaticContainer;
use Piwik\DataAccess\ArchiveWriter;
use Piwik\DataTable;
use Piwik\Date;
use Piwik\Period\Range;
use Piwik\Site;

class ArchiveState
{
    public const COMPLETE = 'complete';
    public const INCOMPLETE = 'incomplete';
    public const INVALIDATED = 'invalidated';

    /**
     * @var ArchiveInvalidator
     */
    private $invalidator;

    public function __construct(ArchiveInvalidator $invalidator = null)
    {
        $this->invalidator = $invalidator ?? StaticContainer::get(ArchiveInvalidator::class);
    }

    /**
     * @param array{date1: string, date2: string, idsite: string, ts_archived: string} $archiveData
     * @param array<string, array<int>> $archiveIds archives ids indexed by period
     * @param array<int, array<string, array<int, int>>> $archiveStates archive states indexed by site and period
     */
    public function addMetadataToResultCollection(
        DataCollection $collection,
        array $archiveData,
        array $archiveIds,
        array $archiveStates
    ): void {
        $periodsTsArchived = [];

        foreach ($archiveData as $archive) {
            $idSite = $archive['idsite'];
            $period = $archive['date1'] . ',' . $archive['date2'];

            $periodsTsArchived[$idSite][$period] = $archive['ts_archived'];
        }

        foreach ($periodsTsArchived as $idSite => $periods) {
            $site = new Site($idSite);
            $incompleteDays = $this->invalidator->getDaysWithRememberedInvalidationsForSite($site->getId());

            foreach ($periods as $period => $tsArchived) {
                $state = $this->checkArchiveStates($site, $period, $archiveIds, $archiveStates);

                $range = new Range('day', $period);
                $state = $this->checkTsArchived($state, $site, $range, $tsArchived);
                $state = $this->checkDaysRememberedToBeIncomplete($state, $range, $incompleteDays);

                if (null === $state) {
                    // do not set metadata, if no state was determined,
                    // to avoid generating unexpected default rows
                    continue;
                }

                $collection->addMetadata(
                    $idSite,
                    $period,
                    DataTable::ARCHIVE_STATE_METADATA_NAME,
                    $state
                );
            }
        }
    }

    /**
     * @param array<string, array<int>> $archiveIds
     * @param array<int, array<string, array<int, int>>> $archiveStates
     */
    private function checkArchiveStates(
        Site $site,
        string $period,
        array $archiveIds,
        array $archiveStates
    ): ?string {
        $idSite = $site->getId();

        $availableStates = array_intersect_key(
            $archiveStates[$idSite][$period] ?? [],
            array_flip($archiveIds[$period] ?? [])
        );

        if ([] === $availableStates) {
            // do not determine state if no archives were used
            return null;
        }

        if (in_array(ArchiveWriter::DONE_INVALIDATED, $availableStates)) {
            // archive has been invalidated
            return self::INVALIDATED;
        }

        // all archives not invalidated should be complete
        // includes DONE_OK, DONE_OK_TEMPORARY and DONE_PARTIAL
        return self::COMPLETE;
    }

    /**
     * @param array<string> $incompleteDays
     */
    private function checkDaysRememberedToBeIncomplete(
        ?string $state,
        Range $range,
        array $incompleteDays
    ): ?string {
        if ([] === $incompleteDays || in_array($state, [self::INCOMPLETE, self::INVALIDATED])) {
            // only missing archives or those detected as complete are relevant
            // for remembered invalidations marking them as incomplete
            return $state;
        }

        foreach ($range->getSubperiods() as $subPeriod) {
            $subPeriodDay = $subPeriod->toString('Y-m-d');

            if (!in_array($subPeriodDay, $incompleteDays)) {
                continue;
            }

            // archive has received more requests after it was already processed
            return self::INCOMPLETE;
        }

        return $state;
    }

    private function checkTsArchived(
        ?string $state,
        Site $site,
        Range $range,
        string $tsArchived
    ): ?string {
        if (self::COMPLETE !== $state) {
            // only archives detected as complete can be archived before range end
            return $state;
        }

        $rangeEndTimestamp = $range->getDateTimeEnd()->setTimezone($site->getTimezone())->getTimestamp();
        $tsArchivedTimestamp = Date::factory($tsArchived)->getTimestamp();

        if ($tsArchivedTimestamp <= $rangeEndTimestamp) {
            return self::INCOMPLETE;
        }

        return $state;
    }
}
