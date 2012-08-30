<?php
namespace RobertLemke\Akismet;

/*                                                                        *
 * This script belongs to the FLOW3 package "RobertLemke.Akismet".        *
 *                                                                        */

use TYPO3\FLOW3\Http\Request;
use TYPO3\FLOW3\Http\Response;
use TYPO3\FLOW3\Http\Uri;

use TYPO3\FLOW3\Annotations as FLOW3;

/**
 * An Akismet service wrapper class for FLOW3
 *
 * @FLOW3\Scope("singleton")
 */
class Service {

	const API_VERSION = '1.1';

	/**
	 * @FLOW3\Inject
	 * @var \TYPO3\FLOW3\Http\Client\Browser
	 */
	protected $browser;

	/**
	 * @FLOW3\Inject
	 * @var \TYPO3\FLOW3\Http\Client\RequestEngineInterface
	 */
	protected $browserRequestEngine;

	/**
	 * @FLOW3\Inject
	 * @var \TYPO3\FLOW3\Log\SystemLoggerInterface
	 */
	protected $systemLogger;

	/**
	 * @var array
	 */
	protected $settings;

	/**
	 * @var \TYPO3\FLOW3\Http\Request
	 */
	protected $currentRequest;

	/**
	 * @param array $settings
	 * @return void
	 */
	public function injectSettings(array $settings) {
		$this->settings = $settings;
	}

	/**
	 * Initialize this service
	 *
	 * @return void
	 */
	public function initializeObject() {
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
	 * @param \TYPO3\FLOW3\Http\Request $request
	 * @return void
	 * @api
	 */
	public function setCurrentRequest(Request $request) {
		$this->currentRequest = $request;
	}

	/**
	 * Checks if the currently configured API key in combination with the blog URI
	 * is valid according to the Akismet service.
	 *
	 * @return boolean TRUE if the credentials are correct, otherwise FALSE
	 * @api
	 */
	public function isApiKeyValid() {
		$response = $this->sendRequest('verify-key', array(), FALSE);
		return ($response->getContent() === 'valid');
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
	 * @return boolean TRUE if the comment is considered spam, otherwise FALSE
	 * @api
	 */
	public function isCommentSpam($permaLink, $content, $type, $author = '', $authorEmailAddress = '', $authorUri = '') {
		$arguments = array(
			'permalink' => $permaLink,
			'comment_type' => $type,
			'comment_author' => $author,
			'comment_author_email' => $authorEmailAddress,
			'comment_author_url' => $authorUri,
			'comment_content' => $content
		);
		$response = $this->sendRequest('comment-check', $arguments);
		switch ($response->getContent()) {
			case 'true':
				$this->systemLogger->log(sprintf('Akismet determined that the given comment referring to content with permalink "%s" is spam.', $permaLink), LOG_INFO);
				return TRUE;
			case 'false':
				$this->systemLogger->log(sprintf('Akismet determined that the given comment referring to content with permalink "%s" is not spam.', $permaLink), LOG_INFO);
				return FALSE;
			default:
				throw new Exception\ConnectionException('API error: ' . $response->getContent() . ' ' . $response->getHeader('X-akismet-debug-help'), 1335192487);
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
	 * @api
	 */
	public function submitSpam($permaLink, $content, $type, $author = '', $authorEmailAddress = '', $authorUri = '') {
		$arguments = array(
			'permalink' => $permaLink,
			'comment_type' => $type,
			'comment_author' => $author,
			'comment_author_email' => $authorEmailAddress,
			'comment_author_url' => $authorUri,
			'comment_content' => $content
		);
		$this->sendRequest('submit-spam', $arguments);
		$this->systemLogger->log(sprintf('Submitted new sample of spam (comment for "%s") to Akismet.', $permaLink), LOG_INFO);
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
	 * @api
	 */
	public function submitHam($permaLink, $content, $type, $author = '', $authorEmailAddress = '', $authorUri = '') {
		$arguments = array(
			'permalink' => $permaLink,
			'comment_type' => $type,
			'comment_author' => $author,
			'comment_author_email' => $authorEmailAddress,
			'comment_author_url' => $authorUri,
			'comment_content' => $content
		);
		$this->sendRequest('submit-ham', $arguments);
		$this->systemLogger->log(sprintf('Submitted new sample of ham (comment for "%s") to Akismet.', $permaLink), LOG_INFO);
	}

	/**
	 * Send a command to the Akismet "REST" (well, ...) API.
	 *
	 * @param string $command Name of the command according to the API documentation, for example "verify-key"
	 * @param array $arguments Post arguments (field => value) to send to the Askismet server
	 * @param boolean $useAccountSubdomain If the api key should be prepended to the host name (default case)
	 * @return \TYPO3\FLOW3\Http\Response The response from the POST request
	 */
	protected function sendRequest($command, array $arguments, $useAccountSubdomain = TRUE) {
		$arguments['key'] = $this->settings['apiKey'];
		$arguments['blog'] = $this->settings['blogUri'];
		$arguments['user_ip'] = $this->currentRequest->getClientIpAddress();
		$arguments['user_agent'] = $this->currentRequest->getHeaders()->get('User-Agent');
		$arguments['referrer'] = $this->currentRequest->getHeaders()->get('Referer');

		$uri = new Uri('http://' . ($useAccountSubdomain ? $this->settings['apiKey'] . '.' :  '') . $this->settings['serviceHost'] . '/' . self::API_VERSION . '/' . $command);
		$request = Request::create($uri, 'POST', $arguments);
		$request->setContent('');

		$this->systemLogger->log('Sending request to Akismet service', LOG_DEBUG, array('uri' => (string)$uri, 'arguments' => $arguments));
		$response = $this->browser->sendRequest($request);

		if (!is_object($response)) {
			throw new Exception\ConnectionException('Could not connect to Akismet API, virtual browser returned "' . var_export($response, TRUE) . '"', 1335190115);
		}
		if ($response->getStatusCode() !== 200) {
			throw new Exception\ConnectionException('The Akismet API server did not respond with a 200 status code: "' . $response->getStatus() . '"', 1335190117);
		}

		return $response;
	}
}