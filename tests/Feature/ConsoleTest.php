<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Feature;

use BrewPHP\Console\Commands\ServeCommand;
use BrewPHP\Console\Output;
use BrewPHP\Core\Application;
use BrewPHP\Tests\TestCase;

final class ConsoleTest extends TestCase
{
    /**
     * @param list<string> $arguments
     * @param list<string> $answers
     * @param array<string, mixed> $settings
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $arguments, array $answers = [], array $settings = [], ?Application $app = null): array
    {
        $app ??= $this->makeApp($settings);
        $output = Output::buffered();
        $stdin = fopen('php://memory', 'r+');
        self::assertIsResource($stdin);
        fwrite($stdin, implode("\n", $answers) . "\n");
        rewind($stdin);

        $code = $app->console()->run(['manage.php', ...$arguments], $output, $stdin);

        return [$code, $output->fetch()];
    }

    public function testListShowsBuiltinAndAppCommands(): void
    {
        [$code, $out] = $this->runCommand([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('Available commands:', $out);
        foreach (['about', 'check', 'key:generate', 'make:command', 'routes', 'serve', 'demo:hello'] as $name) {
            self::assertStringContainsString($name, $out);
        }
        self::assertStringContainsString('[runserver]', $out);
        self::assertSame($out, $this->runCommand(['list'])[1]);
    }

    public function testHelp(): void
    {
        [$code, $out] = $this->runCommand(['demo:hello', '--help']);

        self::assertSame(0, $code);
        self::assertStringContainsString('php manage.php demo:hello [options] [<who>]', $out);
        self::assertStringContainsString('-s, --shout', $out);
        self::assertMatchesRegularExpression("/--times=VALUE +How many times \[default: '1'\]/", $out);
        self::assertStringContainsString('--no-interaction', $out);

        self::assertStringContainsString('Aliases: runserver', $this->runCommand(['help', 'serve'])[1]);
        self::assertStringContainsString('Available commands:', $this->runCommand(['help'])[1]);
    }

    public function testRunsAppCommandWithArgumentsAndOptions(): void
    {
        self::assertSame([0, 'Hello, world' . PHP_EOL], $this->runCommand(['demo:hello']));
        self::assertSame([0, str_repeat('HELLO, ADA' . PHP_EOL, 2)], $this->runCommand(['demo:hello', 'Ada', '-s', '--times=2']));
    }

    public function testUsageErrorsExitWithTwo(): void
    {
        [$code, $out] = $this->runCommand(['demo:hello', '--bogus']);
        self::assertSame(2, $code);
        self::assertStringContainsString('The "--bogus" option does not exist.', $out);

        [$code, $out] = $this->runCommand(['rotues']);
        self::assertSame(2, $code);
        self::assertStringContainsString('Did you mean: routes?', $out);
    }

    public function testVersion(): void
    {
        self::assertSame([0, 'BrewPHP ' . Application::VERSION . PHP_EOL], $this->runCommand(['-V']));
    }

    public function testAbout(): void
    {
        [$code, $out] = $this->runCommand(['about']);
        self::assertSame(0, $code);
        self::assertStringContainsString('BrewPHP version', $out);
        self::assertStringContainsString('apps.demo', $out);

        [, $json] = $this->runCommand(['about', '--json']);
        $data = json_decode($json, true);
        self::assertIsArray($data);
        self::assertSame(PHP_VERSION, $data['Environment']['PHP version']);
        self::assertSame('ON', $data['Environment']['Debug mode']);
    }

    public function testRoutes(): void
    {
        [$code, $out] = $this->runCommand(['routes']);
        self::assertSame(0, $code);
        self::assertStringContainsString('/hello/<str:name>/', $out);
        self::assertStringContainsString('Apps\Demo\GreetingController::hello', $out);
        self::assertStringContainsString('TagMiddleware', $out);
        self::assertStringContainsString('POST', $out);
        self::assertStringContainsString('9 routes', $out);

        [, $json] = $this->runCommand(['routes', '--json', '--filter=demo']);
        $rows = json_decode($json, true);
        self::assertIsArray($rows);
        self::assertCount(2, $rows);
        self::assertSame('demo:tagged', $rows[0]['name']);

        self::assertStringContainsString('No routes found.', $this->runCommand(['routes', '--filter=zzz'])[1]);
    }

    public function testCheck(): void
    {
        $storage = $this->tempDir();
        [$code, $out] = $this->runCommand(['check'], settings: ['STORAGE_PATH' => $storage]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('System check identified no issues.', $out);

        [$code, $out] = $this->runCommand(['check'], settings: ['STORAGE_PATH' => $storage, 'SECRET_KEY' => '', 'TEMPLATES' => ['DIRS' => ['missing-dir']]]);
        self::assertSame(1, $code);
        self::assertStringContainsString('[security.E001]', $out);
        self::assertStringContainsString('HINT: Run "php manage.php key:generate"', $out);
        self::assertStringContainsString('[templates.W001]', $out);

        [$code, $out] = $this->runCommand(['check', '--deploy'], settings: ['STORAGE_PATH' => $storage]);
        self::assertSame(0, $code, 'warnings alone do not fail');
        foreach (['security.W018', 'security.W020', 'security.W004'] as $id) {
            self::assertStringContainsString($id, $out);
        }
        self::assertSame(1, $this->runCommand(['check', '--deploy', '--fail-level=warning'], settings: ['STORAGE_PATH' => $storage])[0]);

        [$code, $out] = $this->runCommand(['check'], settings: ['STORAGE_PATH' => $storage . '/missing']);
        self::assertSame(1, $code);
        self::assertStringContainsString('[core.E001]', $out);
    }

    public function testCheckReportsBrokenUrlconfAndDuplicateNames(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/dupes.php', "<?php return [path('a/', 'a', name: 'x'), path('b/', 'b', name: 'x')];");
        file_put_contents($dir . '/broken.php', "<?php return 'nope';");

        $out = $this->runCommand(['check'], settings: ['STORAGE_PATH' => $dir, 'ROOT_URLCONF' => $dir . '/dupes.php'])[1];
        self::assertStringContainsString('[urls.W001]', $out);

        $out = $this->runCommand(['check'], settings: ['STORAGE_PATH' => $dir, 'ROOT_URLCONF' => $dir . '/broken.php'])[1];
        self::assertStringContainsString('[urls.E001]', $out);
    }

    public function testKeyGenerate(): void
    {
        $project = $this->copyProject();
        $app = $this->makeApp(basePath: $project);

        [$code, $out] = $this->runCommand(['key:generate'], app: $app);
        self::assertSame(1, $code);
        self::assertStringContainsString('No .env file found', $out);

        file_put_contents($project . '/.env', "DEBUG=true\nSECRET_KEY=\n");
        [$code] = $this->runCommand(['key:generate'], app: $app);
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/^DEBUG=true\nSECRET_KEY=[A-Za-z0-9_-]{50}\n$/', (string) file_get_contents($project . '/.env'));
        $first = (string) file_get_contents($project . '/.env');

        // An existing key is kept without --force when not interactive...
        [$code, $out] = $this->runCommand(['key:generate', '-n'], app: $app);
        self::assertSame(1, $code);
        self::assertStringContainsString('Kept the existing key', $out);
        self::assertSame($first, file_get_contents($project . '/.env'));

        // ...replaced when the user agrees...
        $this->runCommand(['key:generate'], ['y'], app: $app);
        self::assertNotSame($first, file_get_contents($project . '/.env'));

        // ...and appended when the line is missing.
        file_put_contents($project . '/.env', "DEBUG=true\n");
        $this->runCommand(['key:generate', '--force'], app: $app);
        self::assertMatchesRegularExpression('/^DEBUG=true\nSECRET_KEY=\S{50}\n$/', (string) file_get_contents($project . '/.env'));

        [$code, $out] = $this->runCommand(['key:generate', '--show'], app: $app);
        self::assertSame(0, $code);
        self::assertSame(51, strlen($out) - strlen(PHP_EOL) + 1);
    }

    public function testMakeCommand(): void
    {
        $project = $this->copyProject();
        $app = $this->makeApp(basePath: $project);

        [$code, $out] = $this->runCommand(['make:command', 'send_report'], app: $app);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Created apps', $out);
        self::assertStringContainsString('php manage.php send-report', $out);

        $file = $project . '/apps/demo/Commands/SendReportCommand.php';
        self::assertFileExists($file);
        $source = (string) file_get_contents($file);
        self::assertStringContainsString('namespace Apps\Demo\Commands;', $source);
        self::assertStringContainsString('final class SendReportCommand extends Command', $source);
        self::assertStringContainsString("public string \$name = 'send-report';", $source);

        // The generated command is discovered and runs.
        [$code, $out] = $this->runCommand(['send-report'], app: $this->makeApp(basePath: $project));
        self::assertSame(0, $code);
        self::assertStringContainsString('send-report ran.', $out);

        [$code, $out] = $this->runCommand(['make:command', 'SendReportCommand'], app: $app);
        self::assertSame(1, $code);
        self::assertStringContainsString('already exists', $out);

        [$code] = $this->runCommand(['make:command', 'Other', '--command=reports:send', '--app=demo', '--force'], app: $app);
        self::assertSame(0, $code);
        self::assertStringContainsString("'reports:send'", (string) file_get_contents($project . '/apps/demo/Commands/OtherCommand.php'));

        self::assertSame(2, $this->runCommand(['make:command', 'X', '--app=nope'], app: $app)[0]);
        self::assertSame(2, $this->runCommand(['make:command', '123'], app: $app)[0]);
    }

    public function testMakeCommandUsesProjectStub(): void
    {
        $project = $this->copyProject();
        mkdir($project . '/stubs');
        file_put_contents($project . '/stubs/command.stub', '<?php // custom {{ class }} {{ command }}');

        $this->runCommand(['make:command', 'Custom'], app: $this->makeApp(basePath: $project));

        self::assertSame('<?php // custom CustomCommand custom', file_get_contents($project . '/apps/demo/Commands/CustomCommand.php'));
    }

    public function testMakeCommandWithoutApps(): void
    {
        [$code, $out] = $this->runCommand(['make:command', 'X'], settings: ['INSTALLED_APPS' => []]);

        self::assertSame(2, $code);
        self::assertStringContainsString('No apps are installed', $out);
    }

    public function testServeAddressParsingAndCommandLine(): void
    {
        self::assertSame(['127.0.0.1', 8000], ServeCommand::parseAddress('127.0.0.1:8000'));
        self::assertSame(['127.0.0.1', 9000], ServeCommand::parseAddress('9000'));
        self::assertSame(['0.0.0.0', 8080], ServeCommand::parseAddress('0.0.0.0:8080'));
        self::assertSame(['localhost', 8000], ServeCommand::parseAddress('localhost'));
        self::assertSame(['::1', 8001], ServeCommand::parseAddress('[::1]:8001'));

        self::assertSame(
            [PHP_BINARY, '-S', '[::1]:8001', '-t', '/srv/public', '/srv/server.php'],
            ServeCommand::commandLine('::1', 8001, '/srv/public', '/srv/server.php'),
        );
        self::assertSame('127.0.0.1:8000', ServeCommand::commandLine('127.0.0.1', 8000, 'p', 'r')[2]);
        self::assertFileExists($this->makeApp()->resourcePath('server.php'));

        [$code, $out] = $this->runCommand(['serve', 'not valid!']);
        self::assertSame(2, $code);
        self::assertStringContainsString('is not a valid address', $out);
        self::assertSame(2, $this->runCommand(['runserver', '70000'])[0]);
    }

    public function testServeWithoutFrontController(): void
    {
        $project = $this->copyProject();
        unlink($project . '/public/index.php');

        [$code, $out] = $this->runCommand(['serve', '--skip-checks'], app: $this->makeApp(basePath: $project));

        self::assertSame(1, $code);
        self::assertStringContainsString('No front controller found', $out);
    }

    public function testUnexpectedErrorsExitWithOne(): void
    {
        [$code, $out] = $this->runCommand(['routes'], settings: ['ROOT_URLCONF' => __FILE__ . '.missing', 'INSTALLED_APPS' => ['demo']]);
        self::assertSame(0, $code, 'a missing URLconf is just an empty router');

        $dir = $this->tempDir();
        file_put_contents($dir . '/urls.php', "<?php return 'nope';");
        [$code, $out] = $this->runCommand(['routes'], settings: ['ROOT_URLCONF' => $dir . '/urls.php']);
        self::assertSame(1, $code);
        self::assertStringContainsString('must return an array', $out);
        self::assertStringContainsString('Run again with -v', $out);

        [, $out] = $this->runCommand(['routes', '-v'], settings: ['ROOT_URLCONF' => $dir . '/urls.php']);
        self::assertStringContainsString('#0 ', $out);
    }
}
