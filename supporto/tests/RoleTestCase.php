<?php

namespace Tests;

/**
 * Il ruolo (hub o site) decide le rotte caricate all'avvio: va impostato prima di creare l'applicazione.
 */
abstract class RoleTestCase extends TestCase
{
    protected string $role = 'site';

    public function createApplication()
    {
        putenv('APP_ROLE='.$this->role);
        $_ENV['APP_ROLE'] = $this->role;
        $_SERVER['APP_ROLE'] = $this->role;

        return parent::createApplication();
    }
}
