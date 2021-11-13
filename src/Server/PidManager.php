<?php
/**
 * nekoimi  2021/10/29 17:12
 */

namespace Lawoole\Server;


use Illuminate\Support\Str;

class PidManager
{
    /**
     * @var string
     */
    protected $pidFile;

    /**
     * PidManager constructor.
     * @param string|null $pidFile
     */
    public function __construct(string $pidFile = null)
    {
        $this->setPidFile($pidFile ?: sys_get_temp_dir() . '/swoole.pid');
    }

    /**
     * @param string $pidFile
     */
    public function setPidFile(string $pidFile): void
    {
        $this->pidFile = $pidFile;
    }

    /**
     * @return string
     */
    public function pidFile(): string
    {
        return $this->pidFile;
    }

    /**
     * @return bool
     */
    public function delete(): bool
    {
        if (is_writable($this->pidFile)) {
            return unlink($this->pidFile);
        }

        return false;
    }

    /**
     * @param int $masterPid
     * @param int $managerPid
     */
    public function write(int $masterPid, int $managerPid): void
    {
        if (!is_writable($this->pidFile)) {
            throw new \RuntimeException(
                sprintf('Pid file "%s" is not writable', $this->pidFile)
            );
        }

        file_put_contents($this->pidFile, $masterPid . '|' . $managerPid);
    }

    /**
     * Read master pid and manager pid from pid file
     *
     * @return Pid
     */
    public function read(): Pid
    {
        $pids = [0, 0];

        if (is_readable($this->pidFile)) {
            $content = file_get_contents($this->pidFile);
            if (Str::contains($content, '|')) {
                $pids = explode('|', $content);
            }
        }

        return new Pid($pids[0], $pids[1]);
    }
}
