<?php
declare(strict_types=1);
namespace Config;
final class Database extends \CodeIgniter\Database\Config
{
    public string $defaultGroup = 'default';
    public array $default = [];
    public array $tests = [];
    public function __construct()
    {
        parent::__construct();
        $this->default = ['database' => getenv('LUNIXI_DATABASE') ?: WRITEPATH . 'lunixi.sqlite', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 10000];
        $this->tests = $this->default;
        if (ENVIRONMENT === 'testing') { $this->defaultGroup = 'tests'; $this->tests['database'] = ':memory:'; }
    }
}
