<?php

use WTF\Context;

class A {
    public $b;

    public function __construct(B $b)
    {
        $this->b = $b;
    }

    
}
class B {
    public $a;

    public function doSomething($bar, A $a)
    {
        $this->a = $a;
        return $bar;
    }
}

class ContextTest extends PHPUnit_Framework_TestCase
{
    protected $context = null;

    public function setUp()
    {
        $this->context = new Context();
    }

    public function testInvokeClassName()
    {
        $this->assertInstanceOf(
            'A',
            $this->context->invoke('A')
        );

        $this->assertInstanceOf(
            'B',
            $this->context->invoke('A')->b
        );
    }

    public function testInvokeAliasClassName()
    {
        $this->context->bind('Alias', 'A');

        $this->assertInstanceOf(
            'A',
            $this->context->invoke('Alias')
        );
    }

    public function testInvokeClosure()
    {
        $this->context->bind('A', function(B $b){
            $a = new A($b);
            return $a;
        });

        $this->assertInstanceOf(
            'A',
            $this->context->invoke('A')
        );

        $this->assertInstanceOf(
            'B',
            $this->context->invoke('A')->b
        );
    }

    public function testCall()
    {
        $this->assertEquals(
            'bar',
            $this->context->call('B@doSomething',
                array('bar' => 'bar')
            )
        );
    }
}
