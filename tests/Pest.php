<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use NetOS\Payments\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Unit', 'Feature');
