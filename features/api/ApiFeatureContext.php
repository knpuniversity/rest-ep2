<?php

use Behat\Behat\Context\ClosuredContextInterface,
    Behat\Behat\Context\TranslatedContextInterface,
    Behat\Behat\Context\BehatContext,
    Behat\Behat\Exception\PendingException;
use Behat\Gherkin\Node\PyStringNode,
    Behat\Gherkin\Node\TableNode;

use Guzzle\Service\Client,
    Guzzle\Http\Exception\BadResponseException;
use Behat\Behat\Event\BaseScenarioEvent;
use Behat\Behat\Event\StepEvent;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\DomCrawler\Crawler;

require_once __DIR__.'/../../vendor/phpunit/phpunit/PHPUnit/Autoload.php';
require_once __DIR__.'/../../vendor/phpunit/phpunit/PHPUnit/Framework/Assert/Functions.php';

/**
 * Class Adapted from: https://github.com/philsturgeon/build-apis-you-wont-hate/blob/master/behat/FeatureContext.php
 *
 * Original credits to Phil Sturgeon (https://twitter.com/philsturgeon)
 * and Ben Corlett (https://twitter.com/ben_corlett).
 *
 * A Behat context aimed at doing one awesome thing: interacting with APIs
 */
class ApiFeatureContext extends BehatContext
{
    /**
     * The Guzzle HTTP Client.
     */
    protected $client;

    /**
     * The current resource
     */
    protected $resource;

    /**
     * The request payload
     */
    protected $requestPayload;

    /**
     * The Guzzle HTTP Response.
     *
     * @var \Guzzle\Http\Message\Response
     */
    protected $response;

    /**
     * The last request that was used to make the response
     */
    protected $lastRequest;

    /**
     * The decoded response object.
     */
    protected $responsePayload;

    /**
     * The current scope within the response payload
     * which conditions are asserted against.
     */
    protected $scope;

    /**
     * On HTML errors, if this is true, it prints out the h1/h2 to the console
     * to help debugging. It's assumed (like with Silex) that the most important
     * messages are sroted in h1/h2 tags. That might not be true for your
     * project, in which case you could set this to false. This isn't configurable
     * anywhere currently - just set this to false in the code or tweak
     * how the printing is done in printLastResponseOnError
     *
     * @var boolean
     */
    protected $useFancyExceptionReporting = true;

    /**
     * @var ConsoleOutput
     */
    protected $output;

    /**
     * Initializes context.
     * Every scenario gets it's own context object.
     *
     * @param array $parameters context parameters (set them up through behat.yml)
     */
    public function __construct(array $parameters)
    {
        $this->useContext('project', new ProjectContext());

        $config = isset($parameters['guzzle']) && is_array($parameters['guzzle']) ? $parameters['guzzle'] : [];
        $config['request.options'] = isset($config['request.options']) ? $config['request.options'] : [];

        // $config['request.options']['Accept'] = 'application/vnd.com.example.api-v1+json';
        // $config['request.options']['Authorization'] = "Bearer {$parameters['access_token']}";

        $this->client = new Client($parameters['base_url'], $config);
    }

    /**
     * @BeforeScenario
     */
    public function clearData()
    {
        $this->getProjectHelper()->reloadDatabase();
    }

    /**
     * @Given /^I have the payload:$/
     */
    public function iHaveThePayload(PyStringNode $requestPayload)
    {
        $this->requestPayload = $requestPayload;
    }

    /**
     * @When /^I request "(GET|PUT|POST|DELETE|PATCH) ([^"]*)"$/
     */
    public function iRequest($httpMethod, $resource)
    {
        $this->resource = $resource;

        $method = strtolower($httpMethod);

        try {
            switch ($httpMethod) {
                case 'PUT':
                case 'POST':
                case 'PATCH':
                    $this->lastRequest = $this
                        ->client
                        ->$method($resource, null, $this->requestPayload);

                    $this->response = $this->lastRequest->send();
                    break;

                default:
                    $this->lastRequest = $this
                        ->client
                        ->$method($resource);

                    $this->response = $this->lastRequest->send();
            }
        } catch (BadResponseException $e) {

            $response = $e->getResponse();

            // Sometimes the request will fail, at which point we have
            // no response at all. Let Guzzle give an error here, it's
            // pretty self-explanatory.
            if ($response === null) {
                throw $e;
            }

            $this->response = $e->getResponse();
        }
    }

    /**
     * @Then /^the response status code should be (?P<code>\d+)$/
     */
    public function iGetAResponse($statusCode)
    {
        $response = $this->getResponse();
        $contentType = $response->getHeader('Content-Type');

        if ($contentType == 'application/json') {
            $bodyOutput = $response->getBody();
        } else {
            $bodyOutput = 'Output is "'.$contentType.'", which is not JSON and is therefore scary. Run the request manually.';
        }
        assertSame((int) $statusCode, (int) $this->getResponse()->getStatusCode(), $bodyOutput);
    }

    /**
     * @Given /^the "([^"]*)" header should be "([^"]*)"$/
     */
    public function theHeaderShouldBe($headerName, $expectedHeaderValue)
    {
        $response = $this->getResponse();
        assertEquals($expectedHeaderValue, (string) $response->getHeader($headerName));
    }

    /**
     * @Then /^the "([^"]*)" property should equal "([^"]*)"$/
     */
    public function thePropertyEquals($property, $expectedValue)
    {
        $payload = $this->getScopePayload();
        $actualValue = $this->arrayGet($payload, $property);

        assertEquals(
            $actualValue,
            $expectedValue,
            "Asserting the [$property] property in current scope equals [$expectedValue]: ".json_encode($payload)
        );
    }

    /**
     * @Then /^the "([^"]*)" property should exist$/
     */
    public function thePropertyExists($property)
    {
        $payload = $this->getScopePayload();

        $message = sprintf(
            'Asserting the [%s] property exists in the scope [%s]: %s',
            $property,
            $this->scope,
            json_encode($payload)
        );

        assertTrue($this->arrayHas($payload, $property), $message);
    }

    /**
     * @Then /^the "([^"]*)" property should not exist$/
     */
    public function thePropertyDoesNotExist($property)
    {
        $payload = $this->getScopePayload();

        $message = sprintf(
            'Asserting the [%s] property does not exist in the scope [%s]: %s',
            $property,
            $this->scope,
            json_encode($payload)
        );

        assertFalse($this->arrayHas($payload, $property), $message);
    }

    /**
     * @Then /^the "([^"]*)" property should be an array$/
     */
    public function thePropertyIsAnArray($property)
    {
        $payload = $this->getScopePayload();

        $actualValue = $this->arrayGet($payload, $property);

        assertTrue(
            is_array($actualValue),
            "Asserting the [$property] property in current scope [{$this->scope}] is an array: ".json_encode($payload)
        );
    }

    /**
     * @Then /^the "([^"]*)" property should be an object$/
     */
    public function thePropertyIsAnObject($property)
    {
        $payload = $this->getScopePayload();

        $actualValue = $this->arrayGet($payload, $property);

        assertTrue(
            is_object($actualValue),
            "Asserting the [$property] property in current scope [{$this->scope}] is an object: ".json_encode($payload)
        );
    }

    /**
     * @Then /^the "([^"]*)" property should be an empty array$/
     */
    public function thePropertyIsAnEmptyArray($property)
    {
        $payload = $this->getScopePayload();
        $scopePayload = $this->arrayGet($payload, $property);

        assertTrue(
            is_array($scopePayload) and $scopePayload === [],
            "Asserting the [$property] property in current scope [{$this->scope}] is an empty array: ".json_encode($payload)
        );
    }

    /**
     * @Then /^the "([^"]*)" property should contain (\d+) item(?:|s)$/
     */
    public function thePropertyContainsItems($property, $count)
    {
        $payload = $this->getScopePayload();

        assertCount(
            $count,
            $this->arrayGet($payload, $property),
            "Asserting the [$property] property contains [$count] items: ".json_encode($payload)
        );
    }

    /**
     * @Then /^the "([^"]*)" property should be an integer$/
     */
    public function thePropertyIsAnInteger($property)
    {
        $payload = $this->getScopePayload();

        isType(
            'int',
            $this->arrayGet($payload, $property),
            "Asserting the [$property] property in current scope [{$this->scope}] is an integer: ".json_encode($payload)
        );
    }

    /**
     * @Then /^the "([^"]*)" property should be a string$/
     */
    public function thePropertyIsAString($property)
    {
        $payload = $this->getScopePayload();

        isType(
            'string',
            $this->arrayGet($payload, $property),
            "Asserting the [$property] property in current scope [{$this->scope}] is a string: ".json_encode($payload)
        );
    }

    /**
     * @Then /^the "([^"]*)" property should be a string equalling "([^"]*)"$/
     */
    public function thePropertyIsAStringEqualling($property, $expectedValue)
    {
        $payload = $this->getScopePayload();

        $this->thePropertyIsAString($property);

        $actualValue = $this->arrayGet($payload, $property);

        assertSame(
            $actualValue,
            $expectedValue,
            "Asserting the [$property] property in current scope [{$this->scope}] is a string equalling [$expectedValue]."
        );
    }

    /**
     * @Then /^the "([^"]*)" property should be a boolean$/
     */
    public function thePropertyIsABoolean($property)
    {
        $payload = $this->getScopePayload();

        assertTrue(
            gettype($this->arrayGet($payload, $property)) == 'boolean',
            "Asserting the [$property] property in current scope [{$this->scope}] is a boolean."
        );
    }

    /**
     * @Then /^the "([^"]*)" property should be a boolean equalling "([^"]*)"$/
     */
    public function thePropertyIsABooleanEqualling($property, $expectedValue)
    {
        $payload = $this->getScopePayload();
        $actualValue = $this->arrayGet($payload, $property);

        if (! in_array($expectedValue, ['true', 'false'])) {
            throw new \InvalidArgumentException("Testing for booleans must be represented by [true] or [false].");
        }

        $this->thePropertyIsABoolean($property);

        assertSame(
            $actualValue,
            $expectedValue == 'true',
            "Asserting the [$property] property in current scope [{$this->scope}] is a boolean equalling [$expectedValue]."
        );
    }

    /**
     * @Then /^the "([^"]*)" property should be an integer equalling "([^"]*)"$/
     */
    public function thePropertyIsAIntegerEqualling($property, $expectedValue)
    {
        $payload = $this->getScopePayload();
        $actualValue = $this->arrayGet($payload, $property);

        $this->thePropertyIsAnInteger($property);

        assertSame(
            $actualValue,
            (int) $expectedValue,
            "Asserting the [$property] property in current scope [{$this->scope}] is an integer equalling [$expectedValue]."
        );
    }

    /**
     * @Then /^the "([^"]*)" property should be either:$/
     */
    public function thePropertyIsEither($property, PyStringNode $options)
    {
        $payload = $this->getScopePayload();
        $actualValue = $this->arrayGet($payload, $property);

        $valid = explode("\n", (string) $options);

        assertTrue(
            in_array($actualValue, $valid),
            sprintf(
                "Asserting the [%s] property in current scope [{$this->scope}] is in array of valid options [%s].",
                $property,
                implode(', ', $valid)
            )
        );
    }

    /**
     * @Then /^scope into the first "([^"]*)" property$/
     */
    public function scopeIntoTheFirstProperty($scope)
    {
        $this->scope = "{$scope}.0";
    }

    /**
     * @Then /^scope into the "([^"]*)" property$/
     */
    public function scopeIntoTheProperty($scope)
    {
        $this->scope = $scope;
    }

    /**
     * @Then /^the following properties should exist:$/
     */
    public function thePropertiesExist(PyStringNode $propertiesString)
    {
        foreach (explode("\n", (string) $propertiesString) as $property) {
            $this->thePropertyExists($property);
        }
    }

    /**
     * @Then /^reset scope$/
     */
    public function resetScope()
    {
        $this->scope = null;
    }

    /**
     * @Transform /^(\d+)$/
     */
    public function castStringToNumber($string)
    {
        return intval($string);
    }

    /**
     * @AfterScenario
     */
    public function printLastResponseOnError(BaseScenarioEvent $scenarioEvent)
    {
        if ($scenarioEvent->getResult() == StepEvent::FAILED) {
            if ($this->response) {
                $body = $this->getResponse()->getBody(true);

                // could we even ask them if they want to print out the error?
                // or do it based on verbosity

                // print some debug details
                $this->printDebug('');
                $this->printDebug('<error>Failure!</error> when making the following request:');
                $this->printDebug(sprintf('<comment>%s</comment>: <info>%s</info>', $this->lastRequest->getMethod(), $this->lastRequest->getUrl())."\n");

                if ($this->response->isContentType('application/json')) {
                    $data = json_decode($body);
                    if ($data === null) {
                        // invalid JSON!
                        $this->printDebug($body);
                    } else {
                        // valid JSON, print it pretty
                        $this->printDebug(json_encode($data, JSON_PRETTY_PRINT));
                    }
                } else {
                    // the response is HTML - see if we should print all of it or some of it
                    $isValidHtml = strpos($body, '</body>') !== false;

                    if ($this->useFancyExceptionReporting && $isValidHtml) {
                        $this->printDebug('<error>Failure!</error> Below is a summary of the HTML response from the server.');

                        // finds the h1 and h2 tags and prints them only
                        $crawler = new Crawler($body);
                        foreach ($crawler->filter('h1, h2')->extract(array('_text')) as $header) {
                            $this->printDebug(sprintf('        '.$header));
                        }
                    } else {
                        $this->printDebug($body);
                    }
                }
            }
        }
    }

    /**
     * Checks the response exists and returns it.
     *
     * @return  Guzzle\Http\Message\Response
     */
    protected function getResponse()
    {
        if (! $this->response) {
            throw new Exception("You must first make a request to check a response.");
        }

        return $this->response;
    }

    /**
     * Return the response payload from the current response.
     *
     * @return  mixed
     */
    protected function getResponsePayload()
    {
        if (! $this->responsePayload) {
            $json = json_decode($this->getResponse()->getBody(true));

            if (json_last_error() !== JSON_ERROR_NONE) {
                $message = 'Failed to decode JSON body ';

                switch (json_last_error()) {
                    case JSON_ERROR_DEPTH:
                        $message .= '(Maximum stack depth exceeded).';
                        break;
                    case JSON_ERROR_STATE_MISMATCH:
                        $message .= '(Underflow or the modes mismatch).';
                        break;
                    case JSON_ERROR_CTRL_CHAR:
                        $message .= '(Unexpected control character found).';
                        break;
                    case JSON_ERROR_SYNTAX:
                        $message .= '(Syntax error, malformed JSON): '."\n\n".$this->getResponse()->getBody(true);
                        break;
                    case JSON_ERROR_UTF8:
                        $message .= '(Malformed UTF-8 characters, possibly incorrectly encoded).';
                        break;
                    default:
                        $message .= '(Unknown error).';
                        break;
                }

                throw new Exception($message);
            }

            $this->responsePayload = $json;
        }

        return $this->responsePayload;
    }

    /**
     * Returns the payload from the current scope within
     * the response.
     *
     * @return mixed
     */
    protected function getScopePayload()
    {
        $payload = $this->getResponsePayload();

        if (! $this->scope) {
            return $payload;
        }

        return $this->arrayGet($payload, $this->scope, true);
    }

    /**
     * Get an item from an array using "dot" notation.
     *
     * Adapted further in this project
     *
     * @copyright   Taylor Otwell
     * @link        http://laravel.com/docs/helpers
     * @param       array   $array
     * @param       string  $key
     * @param bool  $throwOnMissing
     * @param bool  $checkForPresenceOnly If true, this function turns into arrayHas
     *                                    it just returns true/false if it exists
     * @return mixed
     * @throws Exception
     */
    protected function arrayGet($array, $key, $throwOnMissing = false, $checkForPresenceOnly = false)
    {
        // this seems like an odd case :/
        if (is_null($key)) {
            return $checkForPresenceOnly ? true : $array;
        }

        foreach (explode('.', $key) as $segment) {

            if (is_object($array)) {
                if (!property_exists($array, $segment)) {
                    if ($throwOnMissing) {
                        throw new \Exception(sprintf('Cannot find the key "%s"', $key));
                    }

                    // if we're checking for presence, return false - does not exist
                    return $checkForPresenceOnly ? false : null;
                }
                $array = $array->{$segment};

            } elseif (is_array($array)) {
                if (! array_key_exists($segment, $array)) {
                    if ($throwOnMissing) {
                        throw new \Exception(sprintf('Cannot find the key "%s"', $key));
                    }

                    // if we're checking for presence, return false - does not exist
                    return $checkForPresenceOnly ? false : null;
                }
                $array = $array[$segment];
            }
        }

        // if we're checking for presence, return true - *does* exist
        return $checkForPresenceOnly ? true : $array;
    }

    /**
     * Same as arrayGet (handles dot.operators), but just returns a boolean
     *
     * @param $array
     * @param $key
     * @return boolean
     */
    protected function arrayHas($array, $key)
    {
        return $this->arrayGet($array, $key, false, true);
    }

    /**
     * @Given /^the user "([^"]*)" exists$/
     */
    public function theUserExists($username)
    {
        $this->getProjectHelper()->createUser($username.'@foo.com', 'foo', $username);
    }

    /**
     * @Given /^the following programmers exist:$/
     */
    public function theFollowingProgrammersExist(TableNode $table)
    {
        foreach ($table->getHash() as $row) {
            $nickname = $row['nickname'];
            unset($row['nickname']);

            $this->getProjectHelper()->createProgrammer($nickname, null, $row);
        }
    }

    /**
     * @Given /^print last response$/
     */
    public function printLastResponse()
    {
        if ($this->response) {
            $this->printDebug((string) $this->response);
        }
    }

    /**
     * @return ProjectContext
     */
    private function getProjectHelper()
    {
        return $this->getSubcontext('project');
    }

    public function printDebug($string)
    {
        $this->getOutput()->writeln($string);
    }

    /**
     * @return ConsoleOutput
     */
    private function getOutput()
    {
        if ($this->output === null)  {
            $this->output = new ConsoleOutput();
        }

        return $this->output;
    }
}