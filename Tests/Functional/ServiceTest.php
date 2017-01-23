<?php
namespace RobertLemke\Akismet\Tests\Functional;

/*
 * This file is part of the RobertLemke.Akismet package.
 *
 * (c) Robert Lemke
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use RobertLemke\Akismet\Service;
use Neos\Flow\Http\Request;
use Neos\Flow\Http\Uri;

/**
 * Functional tests for the Akismet Service
 */
class Servicetest extends \Neos\Flow\Tests\FunctionalTestCase
{

    /**
     * @var Service
     */
    protected $service;

    /**
     * Set up for this test case
     */
    public function setUp()
    {
        parent::setUp();
        $currentRequest = Request::create(new Uri('http://robertlemke.com/blog/posts/functional-test-post.html'));
        $this->service = $this->objectManager->get(Service::class);
        $this->service->setCurrentRequest($currentRequest);
    }

    /**
     * @test
     */
    public function isApiKeyValidReturnsFalseOnInvalidApiKey()
    {
        $settings = array(
            'serviceHost' => 'rest.akismet.com',
            'apiKey' => 'invalidapikey',
            'blogUri' => 'http://akismet.com'
        );
        $this->service->injectSettings($settings);
        $this->assertFalse($this->service->isApiKeyValid());
    }

}
