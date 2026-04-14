<?php

declare(strict_types=1);

namespace ibibicloud\cloudflare\facade;

use think\Facade;

class D1 extends Facade
{
    protected static function getFacadeClass(): string
    {
    	return 'ibibicloud\cloudflare\D1';
    }
}