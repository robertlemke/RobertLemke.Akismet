<?php
namespace RobertLemke\Akismet\Tests\Functional;

/*                                                                        *
 * This script belongs to the FLOW3 package "RobertLemke.Akismet".        *
 *                                                                        */

use TYPO3\Flow\Http\Request;
use TYPO3\Flow\Http\Uri;

/**
 * Functional tests for the Akismet Service
 */
class Servicetest extends \TYPO3\Flow\Tests\FunctionalTestCase {

	/**
	 * @var boolean
	 */
	protected $testableHttpEnabled = TRUE;

	/**
	 * @var \RobertLemke\Akismet\Service
	 */
	protected $service;

	/**
	 * Set up for this test case
	 */
	public function setUp() {
		parent::setUp();
		$currentRequest = Request::create(new Uri('http://robertlemke.com/blog/posts/functional-test-post.html'));
		$this->service = $this->objectManager->get('RobertLemke\Akismet\Service');
		$this->service->setCurrentRequest($currentRequest);
	}

	/**
	 * @test
	 */
	public function isApiKeyValidReturnsFalseOnInvalidApiKey() {
		$settings = array(
			'serviceHost' => 'rest.akismet.com',
			'apiKey' => 'invalidapikey',
			'blogUri' => 'http://akismet.com'
		);
		$this->service->injectSettings($settings);
		$this->assertFalse($this->service->isApiKeyValid());
	}

}