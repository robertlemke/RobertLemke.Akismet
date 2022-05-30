<?php
declare(strict_types=1);

namespace RobertLemke\Akismet;

/*
 * This file is part of the RobertLemke.Akismet package.
 *
 * (c) Robert Lemke
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\Http\Client\RequestEngineInterface;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\ObjectManagement\DependencyInjection\DependencyProxy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface as HttpRequestInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * An Akismet service wrapper class for Flow
 *
 * @Flow\Scope("singleton")
 */
class Service
{
    private const API_VERSION = '1.1';

    /**
     * @Flow\Inject
     * @var Browser
     */
    protected $browser;

    /**
     * @Flow\Inject
     * @var RequestEngineInterface
     */
    protected $browserRequestEngine;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var array
     */
    protected $settings;

    /**
     * @var HttpRequestInterface
     */
    protected $currentRequest;

    /**
     * @Flow\Inject
     * @var UriFactoryInterface
     */
    protected $uriFactory;

    /**
     * @Flow\Inject
     * @var ServerRequestFactoryInterface
     */
    protected $serverRequestFactory;

    /**
     * @param array $settings
     * @return void
     */
    public function injectSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    /**
     * Initialize this service
     *
     * @return void
     */
    public function initializeObject(): void
    {
        if ($this->browserRequestEngine instanceof DependencyProxy) {
            $this->browserRequestEngine->_activateDependency();
        }
        $this->browser->setRequestEngine($this->browserRequestEngine);
    }

    /**
     * Sets the current browser request which was sent by the user.
     *
     * This service needs some of the information contained in that request because
     * the Akismet servers require it. This method must be called once before you
     * can use any of the other methods. From an ActionController you'd just make
     * a call like the following:
     *
     * $akismet->setCurrentRequest($this->request->getHttpRequest());
     *
     * @param HttpRequestInterface $request
     * @return void
     * @api
     */
    public function setCurrentRequest(HttpRequestInterface $request): void
    {
        $this->currentRequest = $request;
    }

    /**
     * Checks if the currently configured API key in combination with the blog URI
     * is valid according to the Akismet service.
     *
     * @return boolean TRUE if the credentials are correct, otherwise FALSE
     * @throws Exception\ConnectionException
     * @api
     */
    public function isApiKeyValid(): bool
    {
        $response = $this->sendRequest('verify-key', [], false);

        return ($response->getBody()->getContents() === 'valid');
    }

    /**
     * Checks if the given comment is considered spam by the service.
     *
     * @param string $permaLink An absolute, permanent URI pointing to the item which was commented
     * @param string $content The actual content of the comment sent
     * @param string $type The comment type – can be any string, for example "post", "trackback" or the like
     * @param string $author The author name specified
     * @param string $authorEmailAddress The email address specified
     * @param string $authorUri A URI specified linking to the author's homepage or similar
     * @return bool TRUE if the comment is considered spam, otherwise FALSE
     * @throws Exception\ConnectionException
     * @api
     */
    public function isCommentSpam(string $permaLink, string $content, string $type, string $author = '', string $authorEmailAddress = '', string $authorUri = ''): bool
    {
        if ($this->settings['apiKey'] === '' || $this->settings['apiKey'] === null) {
            $this->logger->debug('Could not check comment for spam because no Akismet API key was provided in the settings.', LogEnvironment::fromMethodName(__METHOD__));

            return false;
        }

        $arguments = [
            'permalink' => $permaLink,
            'comment_type' => $type,
            'comment_author' => $author,
            'comment_author_email' => $authorEmailAddress,
            'comment_author_url' => $authorUri,
            'comment_content' => $content
        ];
        $response = $this->sendRequest('comment-check', $arguments);
        switch ($response->getBody()->getContents()) {
            case 'true':
                $this->logger->info(sprintf('Akismet determined that the given comment referring to content with permalink "%s" is spam.', $permaLink), LogEnvironment::fromMethodName(__METHOD__));

                return true;
            case 'false':
                $this->logger->info(sprintf('Akismet determined that the given comment referring to content with permalink "%s" is not spam.', $permaLink), LogEnvironment::fromMethodName(__METHOD__));

                return false;
            default:
                throw new Exception\ConnectionException('API error: ' . $response->getBody()->getContents() . ' ' . reset($response->getHeaders()['X-akismet-debug-help']), 1335192487);
        }
    }

    /**
     * Submits missed spam comment which hasn't been recognized.
     *
     * @param string $permaLink An absolute, permanent URI pointing to the item which was commented
     * @param string $content The actual content of the comment sent
     * @param string $type The comment type – can be any string, for example "post", "trackback" or the like
     * @param string $author The author name specified
     * @param string $authorEmailAddress The email address specified
     * @param string $authorUri A URI specified linking to the author's homepage or similar
     * @return void
     * @throws Exception\ConnectionException
     * @api
     */
    public function submitSpam(string $permaLink, string $content, string $type, string $author = '', string $authorEmailAddress = '', string $authorUri = ''): void
    {
        if ($this->settings['apiKey'] === '') {
            $this->logger->warning('Could not submit new spam sample to Akismet because no API key was provided in the settings.', LogEnvironment::fromMethodName(__METHOD__));
        }

        $arguments = [
            'permalink' => $permaLink,
            'comment_type' => $type,
            'comment_author' => $author,
            'comment_author_email' => $authorEmailAddress,
            'comment_author_url' => $authorUri,
            'comment_content' => $content
        ];
        $this->sendRequest('submit-spam', $arguments);
        $this->logger->info(sprintf('Submitted new sample of spam (comment for "%s") to Akismet.', $permaLink), LogEnvironment::fromMethodName(__METHOD__));
    }

    /**
     * Submits ham – false positives which have been identified as spam by the service.
     *
     * @param string $permaLink An absolute, permanent URI pointing to the item which was commented
     * @param string $content The actual content of the comment sent
     * @param string $type The comment type – can be any string, for example "post", "trackback" or the like
     * @param string $author The author name specified
     * @param string $authorEmailAddress The email address specified
     * @param string $authorUri A URI specified linking to the author's homepage or similar
     * @return void
     * @throws Exception\ConnectionException
     * @api
     */
    public function submitHam(string $permaLink, string $content, string $type, string $author = '', string $authorEmailAddress = '', string $authorUri = ''): void
    {
        if ($this->settings['apiKey'] === '') {
            $this->logger->log('Could not submit new ham sample to Akismet because no API key was provided in the settings.', LOG_WARNING);
        }

        $arguments = [
            'permalink' => $permaLink,
            'comment_type' => $type,
            'comment_author' => $author,
            'comment_author_email' => $authorEmailAddress,
            'comment_author_url' => $authorUri,
            'comment_content' => $content
        ];
        $this->sendRequest('submit-ham', $arguments);
        $this->logger->info(sprintf('Submitted new sample of ham (comment for "%s") to Akismet.', $permaLink), LogEnvironment::fromMethodName(__METHOD__));
    }

    /**
     * Send a command to the Akismet "REST" (well, ...) API.
     *
     * @param string $command Name of the command according to the API documentation, for example "verify-key"
     * @param array $arguments Post arguments (field => value) to send to the Askismet server
     * @param boolean $useAccountSubdomain If the api key should be prepended to the host name (default case)
     * @return ResponseInterface The response from the POST request
     * @throws Exception\ConnectionException
     */
    protected function sendRequest(string $command, array $arguments, bool $useAccountSubdomain = true): ResponseInterface
    {
        $arguments['key'] = $this->settings['apiKey'];
        $arguments['blog'] = $this->settings['blogUri'];
        $arguments['user_ip'] = $this->currentRequest->getAttribute(ServerRequestAttributes::CLIENT_IP);
        $arguments['user_agent'] = $this->currentRequest->getHeader('User-Agent');
        $arguments['referrer'] = $this->currentRequest->getHeader('Referer');

        $uri = $this->uriFactory->createUri('http://' . ($useAccountSubdomain ? $this->settings['apiKey'] . '.' : '') . $this->settings['serviceHost'] . '/' . self::API_VERSION . '/' . $command);
        $request = $this->serverRequestFactory->createServerRequest('POST', $uri);

        $this->logger->debug('Sending request to Akismet service', array_merge(LogEnvironment::fromMethodName(__METHOD__), ['uri' => (string)$uri, 'arguments' => $arguments]));
        $response = $this->browser->sendRequest($request);

        if (!is_object($response)) {
            throw new Exception\ConnectionException('Could not connect to Akismet API, virtual browser returned "' . var_export($response, true) . '"', 1335190115);
        }
        if ($response->getStatusCode() !== 200) {
            throw new Exception\ConnectionException('The Akismet API server did not respond with a 200 status code: "' . $response->getStatusCode() . '"', 1335190117);
        }

        return $response;
    }
}
