<?php 
namespace DVICD\Providers;

class SellSite{
    private $instances;

    public function __construct(array $instances) {
        $this->instances = $instances;
    }

    public function __call($name, $arguments) {
        foreach ($this->instances as $instance) {
            if (method_exists($instance, $name)) {
                return call_user_func_array([$instance, $name], $arguments);
            }
        }

        throw new \Exception("Method $name not found in any registered class.");
    }

}