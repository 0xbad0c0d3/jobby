<?php

namespace Jobby\Tests;

use Jobby\Helper;
use Jobby\Jobby;
use SuperClosure\SerializableClosure;

/**
 * @coversDefaultClass Jobby\Jobby
 */
class JobbyTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var string
     */
    private $logFile;

    /**
     * @var Helper
     */
    private $helper;

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this->logFile = __DIR__ . '/_files/JobbyTest.log';
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
        
        $this->helper = new Helper();
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown()
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testShell()
    {
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldShell',
            [
                'command'  => 'php ' . __DIR__ . '/_files/helloworld.php',
                'schedule' => '* * * * *',
                'output'   => $this->logFile,
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        $this->assertEquals('Hello World!', $this->getLogContent());
    }

    /**
     * @return void
     */
    public function testBackgroundProcessIsNotSpawnedIfJobIsNotDueToBeRun()
    {
        $hour = date("H", strtotime("+1 hour"));
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldShell',
            [
                'command'  => 'php ' . __DIR__ . '/_files/helloworld.php',
                'schedule' => "* {$hour} * * *",
                'output'   => $this->logFile,
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        $this->assertFalse(
            file_exists($this->logFile),
            "Failed to assert that log file doesn't exist and that background process did not spawn"
        );
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testSuperClosure()
    {
        $fn = static function () {
            echo 'Another function!';

            return true;
        };

        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldClosure',
            [
                'command'  => new SerializableClosure($fn),
                'schedule' => '* * * * *',
                'output'   => $this->logFile,
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        $this->assertEquals('Another function!', $this->getLogContent());
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testClosure()
    {
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldClosure',
            [
                'command'  => static function () {
                    echo 'A function!';

                    return true;
                },
                'schedule' => '* * * * *',
                'output'   => $this->logFile,
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        $this->assertEquals('A function!', $this->getLogContent());
    }

    /**
     * @covers ::add
     * @covers ::run
     */
    public function testShouldRunAllJobsAdded()
    {
        $jobby = new Jobby(['output' => $this->logFile]);
        $jobby->add(
            'job-1',
            [
                'schedule' => '* * * * *',
                'command'  => static function () {
                    echo 'job-1';

                    return true;
                },
            ]
        );
        $jobby->add(
            'job-2',
            [
                'schedule' => '* * * * *',
                'command'  => static function () {
                    echo 'job-2';

                    return true;
                },
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        $this->assertContains('job-1', $this->getLogContent());
        $this->assertContains('job-2', $this->getLogContent());
    }

    /**
     * This is the same test as testClosure but (!) we use the default
     * options to set the output file.
     */
    public function testDefaultOptionsShouldBeMerged()
    {
        $jobby = new Jobby(['output' => $this->logFile]);
        $jobby->add(
            'HelloWorldClosure',
            [
                'command'  => static function () {
                    echo "A function!";

                    return true;
                },
                'schedule' => '* * * * *',
            ]
        );
        $jobby->run();

        // Job runs asynchronously, so wait a bit
        sleep($this->getSleepTime());

        $this->assertEquals('A function!', $this->getLogContent());
    }

    /**
     * @covers ::getDefaultConfig
     */
    public function testDefaultConfig()
    {
        $jobby = new Jobby();
        $config = $jobby->getDefaultConfig();

        $this->assertNull($config['recipients']);
        $this->assertEquals('sendmail', $config['mailer']);
        $this->assertNull($config['runAs']);
        $this->assertNull($config['output']);
        $this->assertEquals('Y-m-d H:i:s', $config['dateFormat']);
        $this->assertTrue($config['enabled']);
        $this->assertFalse($config['debug']);
    }

    /**
     * @covers ::setConfig
     * @covers ::getConfig
     */
    public function testSetConfig()
    {
        $jobby = new Jobby();
        $oldCfg = $jobby->getConfig();

        $jobby->setConfig(['dateFormat' => 'foo bar']);
        $newCfg = $jobby->getConfig();

        $this->assertEquals(count($oldCfg), count($newCfg));
        $this->assertEquals('foo bar', $newCfg['dateFormat']);
    }

    /**
     * @covers ::getJobs
     */
    public function testGetJobs()
    {
        $jobby = new Jobby();
        $this->assertCount(0,$jobby->getJobs());
        
        $jobby->add(
            'test job1',
            [
                'command' => 'test',
                'schedule' => '* * * * *'
            ]
        );

        $jobby->add(
            'test job2',
            [
                'command' => 'test',
                'schedule' => '* * * * *'
            ]
        );

        $this->assertCount(2,$jobby->getJobs());
    }

    /**
     * @covers ::add
     * @expectedException \Jobby\Exception
     */
    public function testExceptionOnMissingJobOptionCommand()
    {
        $jobby = new Jobby();

        $jobby->add(
            'should fail',
            [
                'schedule' => '* * * * *',
            ]
        );
    }

    /**
     * @covers ::add
     * @expectedException \Jobby\Exception
     */
    public function testExceptionOnMissingJobOptionSchedule()
    {
        $jobby = new Jobby();

        $jobby->add(
            'should fail',
            [
                'command' => static function () {
                },
            ]
        );
    }

    public function testDependencies(){
        $jobby = new Jobby();

        $jobby->add('first', [
            'closure' => function () {
                echo 'second,';
                sleep(2);

                return true;
            },
            'output' => $this->logFile,
            'schedule' => '* * * * *',
        ]);
        $jobby->add('second', [
            'closure' => function () {
                echo "first,";
                sleep(1);

                return true;
            },
            'dependsOn' => 'first',
            'output' => $this->logFile,
            'schedule' => '* * * * *',
        ]);
        $jobby->add('third', [
            'closure' => function () {
                echo "third";

                return true;
            },
            'dependsOn' => 'first,second',
            'output' => $this->logFile,
            'schedule' => '* * * * *',
        ]);
        $jobby->run();
        sleep(4);
        self::assertEquals('second,first,third', $this->getLogContent());
    }

    public function testRunClass(){
        $jobby = new Jobby();
        $jobby->add('test', [
            'class' => '\\Jobby\\Tests\\SampleClass',
            'schedule' => '* * * * *',
            'output' => $this->logFile,
        ]);
        $jobby->add('test1', [
            'class' => ['\\Jobby\\Tests\\SampleClass' => ['arg1', 'arg2']],
            'schedule' => '* * * * *',
            'output' => $this->logFile,
        ]);
        $jobby->add('test2', [
            'class' => ['\\Jobby\\Tests\\SampleClass' => [], 'custom' => false],
            'schedule' => '* * * * *',
            'output' => $this->logFile,
        ]);
        $jobby->add('test3', [
            'class' => ['\\Jobby\\Tests\\SampleClass' => ['arg1', 'arg2'], 'custom' => 'testarg'],
            'schedule' => '* * * * *',
            'output' => $this->logFile,
        ]);
        $jobby->add('test4', [
            'class' => ['\\Jobby\\Tests\\SampleClass' => ['arg1', 'arg2'], 'custom' => ['testarg', 'test1']],
            'schedule' => '* * * * *',
            'output' => $this->logFile,
        ]);
        $jobby->run();
        sleep(1);
        $lines = explode(PHP_EOL, trim($this->getLogContent()));
        $expect = ['index', 'index arg1, arg2','custom', 'custom arg1, arg2 testarg', 'custom arg1, arg2 testarg, test1'];
        $done = array_intersect($expect, $lines);
        self::assertEquals(count($expect), count($lines), 'The output has wrong number of lines');
        sort($done); sort($expect);
        self::assertEquals($expect, $done);
    }

    /**
     * @covers ::run
     * @covers ::runWindows
     * @covers ::runUnix
     */
    public function testShouldRunJobsAsync()
    {
        $jobby = new Jobby();
        $jobby->add(
            'HelloWorldClosure',
            [
                'command'  => function () {
                    return true;
                },
                'schedule' => '* * * * *',
            ]
        );

        $timeStart = gettimeofday(true);
        $jobby->run();
        $duration = gettimeofday(true) - $timeStart;

        $this->assertLessThan(0.5, $duration);
    }

    public function testShouldFailIfMaxRuntimeExceeded()
    {
        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            $this->markTestSkipped("'maxRuntime' is not supported on Windows");
        }

        $jobby = new Jobby();
        $jobby->add(
            'slow job',
            [
                'command'    => 'sleep 4',
                'schedule'   => '* * * * *',
                'maxRuntime' => 1,
                'output'     => $this->logFile,
            ]
        );

        $jobby->run();
        sleep(2);
        $jobby->run();
        sleep(2);

        $this->assertContains('ERROR: MaxRuntime of 1 secs exceeded!', $this->getLogContent());
    }

    /**
     * @return string
     */
    private function getLogContent()
    {
        return file_get_contents($this->logFile);
    }

    private function getSleepTime()
    {
        return $this->helper->getPlatform() === Helper::UNIX ? 1 : 2;
    }
}
