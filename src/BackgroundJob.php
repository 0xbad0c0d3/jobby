<?php

namespace Jobby;

use ReflectionClass;

class BackgroundJob
{
    use SerializerTrait;
    use HelperTrait;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @var string
     */
    protected $job;

    /**
     * @var string
     */
    protected $tmpDir;

    /**
     * @var array
     */
    protected $config;

    /**
     * @param string $job
     * @param array  $config
     * @param Helper $helper
     */
    public function __construct($job, array $config, Helper $helper = null)
    {
        $this->job = $job;
        $this->config = $config + [
            'recipients'     => null,
            'mailer'         => null,
            'maxRuntime'     => null,
            'smtpHost'       => null,
            'smtpPort'       => null,
            'smtpUsername'   => null,
            'smtpPassword'   => null,
            'smtpSender'     => null,
            'smtpSenderName' => null,
            'smtpSecurity'   => null,
            'runAs'          => null,
            'environment'    => null,
            'runOnHost'      => null,
            'output'         => null,
            'dateFormat'     => null,
            'enabled'        => null,
            'haltDir'        => null,
            'debug'          => null,
            'dependsOn'      => null,
            'class'          => null,
        ];

        $this->helper = $helper ?: new Helper();

        $this->tmpDir = $this->helper->getTempDir();
    }

    public function run()
    {
        $lockFile = $this->getLockFile();

        try {
            $this->checkMaxRuntime($lockFile);
        } catch (Exception $e) {
            $this->log('ERROR: ' . $e->getMessage());
            $this->mail($e->getMessage());

            return;
        }

        if (!$this->shouldRun()) {
            return;
        }

        $lockAcquired = false;
        try {
            $this->helper->acquireLock($lockFile);
            $lockAcquired = true;

            if (isset($this->config['dependsOn'])) {
                $locks = explode(',', $this->config['dependsOn']);
                $n = count($locks) - 1;
                for (; $n >= 0; $n--) {
                    $locks[$n] = $this->getLockFile($locks[$n]);
                }
                $this->helper->waitAllDependencies($locks);
            }

            if(isset($this->config['class'])){
                $this->runClass();
            }elseif (isset($this->config['closure'])) {
                $this->runFunction();
            } else {
                $this->runFile();
            }
        } catch (InfoException $e) {
            $this->log('INFO: ' . $e->getMessage());
        } catch (Exception $e) {
            $this->log('ERROR: ' . $e->getMessage());
            $this->mail($e->getMessage());
        }

        if ($lockAcquired) {
            $this->helper->releaseLock($lockFile);

            // remove log file if empty
            $logfile = $this->helper->getLogfile($this->config['output']);
            if (is_file($logfile) && filesize($logfile) <= 0) {
                unlink($logfile);
            }
        }
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param string $lockFile
     *
     * @throws Exception
     */
    protected function checkMaxRuntime($lockFile)
    {
        $maxRuntime = $this->config['maxRuntime'];
        if ($maxRuntime === null) {
            return;
        }

        if ($this->helper->getPlatform() === Helper::WINDOWS) {
            throw new Exception('"maxRuntime" is not supported on Windows');
        }

        $runtime = (int)$this->helper->getLockLifetime($lockFile);
        if ($runtime < $maxRuntime) {
            return;
        }

        throw new Exception("MaxRuntime of $maxRuntime secs exceeded! Current runtime: $runtime secs");
    }

    /**
     * @param string $message
     */
    protected function mail($message)
    {
        if (empty($this->config['recipients'])) {
            return;
        }

        $this->helper->sendMail(
            $this->job,
            $this->config,
            $message
        );
    }

    /**
     * @param bool $job
     * @return string
     */
    protected function getLockFile($job = false)
    {
        $tmp = $this->tmpDir;
        $job = $job === false ? $this->job : $job;
        $job = $this->helper->escape($job);

        if (!empty($this->config['environment'])) {
            $env = $this->helper->escape($this->config['environment']);

            return "$tmp/$env-$job.lck";
        } else {
            return "$tmp/$job.lck";
        }
    }

    /**
     * @return bool
     */
    protected function shouldRun()
    {
        if (!$this->config['enabled']) {
            return false;
        }

        if (($haltDir = $this->config['haltDir']) !== null) {
            if (file_exists($haltDir . DIRECTORY_SEPARATOR . $this->job)) {
                return false;
            }
        }

        $host = $this->helper->getHost();
        if (strcasecmp($this->config['runOnHost'], $host) != 0) {
            return false;
        }

        return true;
    }

    /**
     * @param string $message
     */
    public function log($message)
    {
        $now = date($this->config['dateFormat'], time());

        if ($logfile = $this->helper->getLogfile($this->config['output'])) {
            file_put_contents($logfile, "[$now] $message\n", FILE_APPEND);
        }
    }

    protected function runFunction()
    {
        $command = $this->getSerializer()->unserialize($this->config['closure']);

        ob_start();
        try {
            $retval = $command();
        } catch (\Throwable $e) {
            echo "Error! " . $e->getMessage() . "\n";
        }
        $content = ob_get_contents();
        if ($logfile = $this->helper->getLogfile($this->config['output'])) {
            file_put_contents($logfile, $content, FILE_APPEND);
        }
        ob_end_clean();

        if ($retval !== true) {
            throw new Exception("Closure did not return true! Returned:\n" . print_r($retval, true));
        }
    }

    protected function runFile()
    {
        // If job should run as another user, we must be on *nix and
        // must have sudo privileges.
        $isUnix = ($this->helper->getPlatform() === Helper::UNIX);
        $useSudo = '';

        if ($isUnix) {
            $runAs = $this->config['runAs'];
            $isRoot = (posix_getuid() === 0);
            if (!empty($runAs) && $isRoot) {
                $useSudo = "sudo -u $runAs ";
            }
        }

        // Start execution. Run in foreground (will block).
        $command = $this->config['command'];
        $logfile = $this->getHelper()->getLogfile($this->config['output']) ?: $this->helper->getSystemNullDevice();
        exec("$useSudo $command 1>> \"$logfile\" 2>&1", $dummy, $retval);

        if ($retval !== 0) {
            throw new Exception("Job exited with status '$retval'.");
        }
    }
}
