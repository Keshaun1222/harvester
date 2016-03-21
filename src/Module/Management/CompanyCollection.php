<?php
namespace Erpk\Harvester\Module\Management;

class CompanyCollection implements \Countable, \IteratorAggregate
{
    protected $original = [];
    protected $filtered = [];

    public function __construct(array $array)
    {
        $this->original = $array;
        $this->reset();
    }

    public function count()
    {
        return count($this->filtered);
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->filtered);
    }

    /**
     * Filters Companies using callback function.
     * @param  callable $callback Callback function. If returns true,
     *                            Company is returned into the filtered results.
     * @return void
     */
    public function filter($callback)
    {
        $this->filtered = array_filter(
            $this->filtered,
            $callback
        );
    }

    /**
     * Resets all previous filters.
     * @return void
     */
    public function reset()
    {
        $this->filtered = $this->original;
    }
}
