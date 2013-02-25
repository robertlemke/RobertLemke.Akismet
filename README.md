Akismet
=======

TYPO3 Flow package which provides convenient access to the Akismet REST service.

Setup
-----

In order to use the service you'll first need to sign up for a (usually) free
account at http://akismet.com/

Next, just include this package into your application by adding it to the required
package of your composer.json file.

Finally add your Akismet credentials to your Settings.yaml:

    RobertLemke:
      Akismet:

        # Defines the host of the Akismet service. Does not have to be changed unless
        # Akismet changes its service entry point.
        serviceHost: 'rest.akismet.com'

        # The API key you have been provided by Akismet
        apiKey: ''

        # The frontpage URI pointing to your blog or the site using Akismet. Must be
        # a full URI, for example "http://robertlemke.com/blog".
        blogUri: ''

Usage
-----

The Service class provides a simple API for checking if a comment is spam or
submitting new spam or ham to the service:

    $isSpam = $service->isCommentSpam($permaLink, $content, $type, $author, $authorEmailAddress, $authorUri);
