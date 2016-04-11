<?php
/**
 * Created by PhpStorm.
 * User: drogjh
 * Date: 3/17/16
 * Time: 9:40 AM
 */


namespace Gb\Helpers\Snowflake;

//感谢 https://github.com/twitter/snowflake
//生成的id总共为64位，
//按照如下方法进行分割
/*
 * 1-41共40bit存放时间，毫秒数 2^40/(365*24*3600*1000) = 39.4年，大概可以用39.4年
 * 42-44共3bit存放数据中心，机房标识，可以拥有2^3=8个机房
 * 45-52共8bit存放机器的id，总共2^8=256台机器，最多可以有256台机器共同拥有发号器
 * 53-55共3bit保留位，不知道后面要干嘛
 * 55-64共9bit，每毫秒可提供 2^9=512个id，就是每秒 512*1000
 *
 * +----------------+------+---------+-----+---------+
 * |      40bit     | 3bit |  8bit   | 3bit|   9bit  |
 * +----------------+------+---------+-----+---------+
 *     毫秒数	     数据中心  机器	  保留位	  序列号
 */
/**
 * $class = new \Gb\Helpers\Snowflake\IdWorker(2, 1);
 * $id = $class->nextId();
 * echo "\n";
 * var_dump($id);
 * var_dump($class->getTimestampFromId($id));
 * var_dump($class->getMaxTimestamp());
 *
 * Class IdWorker
 * @package Gb\Helpers\Snowflake
 */
class IdWorker
{
//	const EPOCH_TIMESTAMP = 1451577600000; //从这个时候开始使用发号器，定为 2016年1月1号0时0分

    /*
     * 64位里面各个位置的使用情况，剩下未做说明的就是时间毫秒数的存放了，64-3-8-3-9-1=40位
     */
    const ALL_BITS = 64;
    const DATA_CENTER_ID_BITS = 3; //数据中心标识位数
    const WORKER_ID_BITS = 8; //机器标识位数
    const PLACEHOLDER_BITS = 3; //保留位置位数
    const SEQUENCE_BITS = 9; //毫秒内自增位

    public $epochTimestamp = 1451577600000;  //从这个时候开始使用发号器，定为 2016年1月1号0时0分

    private $_dataCenterId; //部署中心id
    private $_workerId; //机器id
    private $_placeholder = 0; //占位,暂时保留

    private $_maxDataCenterId; //数据中心ID最大值 7 = -1 ^ (-1 << DATA_CENTER_ID_BITS)
    private $_maxWorkerId; //机器ID最大值 255 = -1 ^ (-1 << WORKER_ID_BITS)
    private $_maxTimestamp; //最大支持时间戳 255 = -1 ^ (-1 << WORKER_ID_BITS)

    private $_placeholderIdShift; //保留位置偏左移位数，9位 = SEQUENCE_BITS(9)
    private $_workerIdShift; //机器ID偏左移位数，12位 = SEQUENCE_BITS(9) + PLACEHOLDER_BITS(3)
    private $_dataCenterIdShift; //数据中心ID偏左移位数，20位 = SEQUENCE_BITS(9) + PLACEHOLDER_BITS(3) + WORKER_ID_BITS(8)
    private $_timestampLeftShift; //数据中心ID偏左移位数，23位 = SEQUENCE_BITS(9) + PLACEHOLDER_BITS(3) + WORKER_ID_BITS(8) + DATA_CENTER_ID_BITS(3)

    private $_sequence = 0;  //毫秒时间内总共生成了多少个
    private $_sequenceMask; //每毫秒最多可以生成多少个id 511 = -1 ^ (-1 << SEQUENCE_BITS)

    private $_lastTimestamp = -1;

    /**
     * IdWorker constructor.
     * @param int $dataCenterId [机房中心id]
     * @param int $workerId [机器id]
     */
    public function __construct($dataCenterId = 1, $workerId = 1)
    {
        self::init($dataCenterId, $workerId);
    }

    /**
     * @param int $dataCenterId [机房中心id]
     * @param int $workerId [机器id]
     * @throws \Exception
     */
    public function init($dataCenterId, $workerId)
    {
        $this->setDataCenterId($dataCenterId);
        $this->setWorkId($workerId);
    }

    /**
     * @param $dataCenterId [机房中心id]
     * @throws \Exception
     */
    public function setDataCenterId($dataCenterId)
    {
        if ($dataCenterId > $this->getMaxDataCenterId() || $dataCenterId < 0) {
            throw new \Exception("dataCenterId can't be greater than {$dataCenterId} or less than 0");
        }
        $this->_dataCenterId = $dataCenterId;
    }

    /**
     * @param $workerId [机器id]
     * @throws \Exception
     */
    public function setWorkId($workerId)
    {
        if ($workerId > $this->getMaxWorkerId() || $workerId < 0) {
            throw new \Exception("workerId can't be greater than {$workerId} or less than 0");
        }
        $this->_workerId = $workerId;
    }

    /**
     * 获取最大的机房数值
     */
    public function getMaxDataCenterId()
    {
        if (is_null($this->_maxDataCenterId)) {
            $this->_maxDataCenterId = -1 ^ (-1 << self::DATA_CENTER_ID_BITS);
        }
        return $this->_maxDataCenterId;
    }

    /**
     * 获取最大的机房数值
     */
    public function getMaxWorkerId()
    {
        if (is_null($this->_maxWorkerId)) {
            $this->_maxWorkerId = -1 ^ (-1 << self::WORKER_ID_BITS);
        }
        return $this->_maxWorkerId;
    }

    /**
     * 最多支持到哪个时间戳
     */
    public function getMaxTimestamp()
    {
        if (is_null($this->_maxTimestamp)) {
            $bits = self::ALL_BITS - $this->getTimestampLeftShift() - 1; //时间戳占用的位置
            $this->_maxTimestamp = ((1 << $bits) + $this->epochTimestamp);
        }
        return $this->_maxTimestamp;
    }

    /**
     * 获取每毫秒最多可以生成多少个id
     */
    public function getSequenceMask()
    {
        if (is_null($this->_sequenceMask)) {
            $this->_sequenceMask = -1 ^ (-1 << self::SEQUENCE_BITS);
        }
        return $this->_sequenceMask;
    }

    /**
     * 获取保留位置偏移位置
     */
    public function getPlaceholderIdShift()
    {
        if (is_null($this->_placeholderIdShift)) {
            $this->_placeholderIdShift = self::SEQUENCE_BITS;
        }
        return $this->_placeholderIdShift;
    }

    /**
     * 获取机器id位置偏移位置
     */
    public function getWorkerIdShift()
    {
        if (is_null($this->_workerIdShift)) {
            $this->_workerIdShift = self::SEQUENCE_BITS + self::PLACEHOLDER_BITS;
        }
        return $this->_workerIdShift;
    }

    /**
     * 获取数据中心位置偏移位置
     */
    public function getDataCenterIdShift()
    {
        if (is_null($this->_dataCenterIdShift)) {
            $this->_dataCenterIdShift = self::SEQUENCE_BITS + self::PLACEHOLDER_BITS + self::WORKER_ID_BITS;
        }
        return $this->_dataCenterIdShift;
    }

    /**
     * 获取数据中心位置偏移位置
     */
    public function getTimestampLeftShift()
    {
        if (is_null($this->_timestampLeftShift)) {
            $this->_timestampLeftShift = self::SEQUENCE_BITS + self::PLACEHOLDER_BITS + self::WORKER_ID_BITS + self::DATA_CENTER_ID_BITS;
        }
        return $this->_timestampLeftShift;
    }

    /**
     * 根据id获取当时的时间戳,毫秒
     * @param $id
     * @return number
     */
    public function getTimestampFromId($id)
    {
        /*
        * Return time
        */
        return bindec(substr(decbin($id), 0, -$this->getTimestampLeftShift())) + $this->epochTimestamp;
    }


    /**
     * 获取当前机器的时间戳
     * @return float
     */
    protected function timeGen()
    {
        return floor(microtime(true) * 1000);
    }

    /**
     * 当前毫秒时间内的id用完了,需要调用此方法等待下一毫秒的到来,然而.我相信 php 1毫秒内生成不了这么多的
     * @param $lastTimestamp
     * @return float
     */
    protected function tilNextMillis($lastTimestamp)
    {
        $timestamp = $this->timeGen();
        while ($timestamp <= $lastTimestamp) {
            $timestamp = $this->timeGen();
        }
        return $timestamp;
    }

    /**
     * 获取id
     * @return int
     * @throws \Exception
     */
    public function nextId()
    {
        $timestamp = $this->timeGen();
        //时间错误,系统时钟出问题了
        if ($timestamp < $this->_lastTimestamp) {
            throw new \Exception('Clock moved backwards.  Refusing to generate id for ' . ($this->_lastTimestamp - $timestamp) . ' milliseconds');
        }

        //已经超出了可以生成的范围了,
        if ($timestamp >= $this->getMaxTimestamp()) {
            throw new \Exception('maxTimestamp is ' . $this->getMaxTimestamp());
        }

        //当前毫秒时间内
        if ($this->_lastTimestamp == $timestamp) {
            //当前毫秒内，则+1
            $this->_sequence = ($this->_sequence + 1) & $this - $this->getSequenceMask();
            if ($this->_sequence == 0) {
                //当前毫秒内计数满了，则等待下一秒
                $timestamp = $this->tilNextMillis($this->_lastTimestamp);
            }
        } else { //不在当前毫秒时间内
            //在跨毫秒时，序列号总是归0，会使得序列号为0的ID比较多，导致ID取模不均匀。所以使用随机0-9的方式，代价是消耗小量id
            $this->_sequence = mt_rand(0, 9);
        }
        //更新最后生成的时间
        $this->_lastTimestamp = $timestamp;

        //ID偏移组合生成最终的ID，并返回ID
        return (($this->_lastTimestamp - $this->epochTimestamp) << $this->getTimestampLeftShift()) |
        ($this->_dataCenterId << $this->getDataCenterIdShift()) |
        ($this->_workerId << $this->getWorkerIdShift()) |
        ($this->_placeholder << $this->getPlaceholderIdShift()) |
        $this->_sequence;
    }
}
