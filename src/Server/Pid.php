<?php
/**
 * nekoimi  2021/10/29 17:18
 */

namespace Lawoole\Server;


class Pid
{
    /**
     * @var int
     */
    private $masterPid;

    /**
     * @var int
     */
    private $managerPid;

    /**
     * Pid constructor.
     * @param int $masterPid
     * @param int $managerPid
     */
    public function __construct(int $masterPid = 0, int $managerPid = 0)
    {
        $this->masterPid = $masterPid;
        $this->managerPid = $managerPid;
    }

    /**
     * @return int
     */
    public function masterPid(): int
    {
        return $this->masterPid;
    }

    /**
     * @return int
     */
    public function managerPid(): int
    {
        return $this->managerPid;
    }

    public function __toString(): string
    {
        return "Pid(masterPid: $this->masterPid, managerPid: $this->managerPid)";
    }
}
