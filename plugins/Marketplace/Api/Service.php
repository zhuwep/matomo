<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Marketplace\Api;

use Piwik\Http;

/**
 *
 */
class Service
{
    const CACHE_TIMEOUT_IN_SECONDS = 1200;
    const HTTP_REQUEST_TIMEOUT = 60;

    /**
     * @var string
     */
    private $domain;

    /**
     * @var null|string
     */
    private $accessToken;

    /**
     * API version to use on the Marketplace
     * @var string
     */
    private $version = '2.0';

    public function __construct($domain)
    {
        $this->domain = $domain;
    }

    public function authenticate($accessToken)
    {
        if (empty($accessToken)) {
            $this->accessToken = null;
        } elseif (ctype_alnum($accessToken)) {
            $this->accessToken = $accessToken;
        }
    }

    /**
     * The API version that will be used on the Marketplace.
     * @return string eg 2.0
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * Returns the currently set access token
     * @return null|string
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    public function hasAccessToken()
    {
        return !empty($this->accessToken);
    }

    /**
     * Downloads data from the given URL via a POST request. If a destination path is given, the downloaded data
     * will be stored in the given path and returned otherwise.
     *
     * Make sure to call {@link authenticate()} to download paid plugins.
     *
     * @param string $url An absolute URL to the marketplace including domain.
     * @param null|string $destinationPath
     * @param null|int $timeout Defaults to 60 seconds see {@link self::HTTP_REQUEST_METHOD}
     * @param bool $getExtendedInfo Return the extended response info for the HTTP request.
     * @return bool|string Returns the downloaded data or true if a destination path was given.
     * @throws \Exception
     */
    public function download($url, $destinationPath = null, $timeout = null, $getExtendedInfo = false)
    {
        $method = Http::getTransportMethod();

        if (!isset($timeout)) {
            $timeout = static::HTTP_REQUEST_TIMEOUT;
        }

        $post = null;
        if ($this->accessToken) {
            $post = array('access_token' => $this->accessToken);
        }

        $file = Http::ensureDestinationDirectoryExists($destinationPath);

        return Http::sendHttpRequestBy(
            $method,
            $url,
            $timeout,
            $userAgent = null,
            $destinationPath,
            $file,
            $followDepth = 0,
            $acceptLanguage = false,
            $acceptInvalidSslCertificate = false,
            $byteRange = false,
            $getExtendedInfo,
            $httpMethod = 'POST',
            $httpUsername = null,
            $httpPassword = null,
            $post
        );
    }

    /**
     * Executes the given API action on the Marketplace using the given params and returns the result.
     *
     * Make sure to call {@link authenticate()} to download paid plugins.
     *
     * @param string $action eg 'plugins', 'plugins/$pluginName/info', ...
     * @param array $params eg array('sort' => 'alpha')
     * @param bool $getExtendedInfo Return the extended response info for the HTTP request.
     * @return mixed
     * @throws Service\Exception
     */
    public function fetch($action, $params, $getExtendedInfo = false)
    {
        $endpoint = sprintf('%s/api/%s/', $this->domain, $this->version);

        $query = Http::buildQuery($params);
        $url   = sprintf('%s%s?%s', $endpoint, $action, $query);

        $response = $this->download($url, null, null, true);
        $result = $response['data'] ?? null;

        if (null !== $result) {
            $result = trim($result);
        }

        if ('' !== $result) {
            $result = json_decode($result, true);

            if (null === $result) {
                $message = sprintf('There was an error reading the response from the Marketplace: Please try again later.');
                throw new Service\Exception($message, Service\Exception::HTTP_ERROR);
            }

            if (!empty($result['error'])) {
                throw new Service\Exception($result['error'], Service\Exception::API_ERROR);
            }
        }

        if (!$getExtendedInfo) {
            return $result;
        }

        $response['data'] = $result;

        return $response;
    }

    /**
     * Get the domain that is used in order to access the Marketplace. Eg http://plugins.piwik.org
     * @return string
     */
    public function getDomain()
    {
        return $this->domain;
    }
}
