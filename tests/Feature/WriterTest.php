<?php

use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Onlime\LaravelSqlReporter\Config;
use Onlime\LaravelSqlReporter\FileName;
use Onlime\LaravelSqlReporter\Formatter;
use Onlime\LaravelSqlReporter\SqlQuery;
use Onlime\LaravelSqlReporter\Writer;

beforeEach(function () {
    $this->now = Carbon::parse('2015-02-03 06:41:31');
    Carbon::setTestNow($this->now);
    $this->formatter = Mockery::mock(Formatter::class);
    $this->config = app(Config::class);
    $this->filename = Mockery::mock(FileName::class);
    $this->writer = new Writer($this->formatter, $this->config, $this->filename);
    $this->directory = __DIR__.'/test-dir/directory';
    setConfig('general.directory', $this->directory);
    $this->filesystem = new Filesystem();
});

afterEach(function () {
    $this->filesystem->deleteDirectory($this->directory);
});

function setConfig(string|array $key, mixed $value =null)
{
    config()->set(
        // prepend all keys with 'sql-reporter.' prefix
        is_array($key)
            ? collect($key)->mapWithKeys(fn (string $value, mixed $key) => ["sql-reporter.$key" => $value])->all()
            : ['sql-reporter.'.$key => $value]
    );
}

it('creates directory if it does not exist for 1st query', function () {
    $query = new SqlQuery(1, 'test', 5.41);
    setConfig('queries.enabled', false);
    expect($this->directory)->not()->toBeDirectory();
    $this->writer->writeQuery($query);
    expect($this->directory)->toBeFile()
        ->and($this->filesystem->allFiles($this->directory))->toBeEmpty();
});

it('does not create directory if it does not exist for 2nd query', function () {
    $query = new SqlQuery(2, 'test', 5.41);
    setConfig('queries.enabled', false);
    expect($this->directory)->not()->toBeDirectory();
    $this->writer->writeQuery($query);
    expect($this->directory)->not()->toBeDirectory();
});

it('creates log file', function () {
    $lineContent = 'Sample log line';
    $expectedContent = "-- header\nSample log line\n";
    $expectedFileName = $this->now->format('Y-m').'-log.sql';

    $query = new SqlQuery(1, 'test', 5.41);
    $this->formatter->shouldReceive('getLine')->once()->with($query)->andReturn($lineContent);
    $this->formatter->shouldReceive('getHeader')->once()->withNoArgs()->andReturn('-- header');

    setConfig('queries.include_pattern', '#.*#i');

    $this->filename->shouldReceive('getLogfile')->times(2)->withNoArgs()->andReturn($expectedFileName);
    $this->writer->writeQuery($query);
    expect($this->directory)->toBeFile()
        ->and($this->filesystem->allFiles($this->directory))->toHaveCount(1)
        ->and($this->directory.'/'.$expectedFileName)->toBeFile()
        ->and(file_get_contents($this->directory.'/'.$expectedFileName))->toBe($expectedContent);
});

it('appends to existing log file', function () {
    $expectedFileName = $this->now->format('Y-m').'-log.sql';
    mkdir($this->directory, 0777, true);
    $initialContent = "Initial file content\n";
    file_put_contents($this->directory.'/'.$expectedFileName, $initialContent);

    $lineContent = 'Sample log line';
    $expectedContent = $initialContent."-- header\nSample log line\n";

    $query = new SqlQuery(1, 'test', 5.41);
    $this->formatter->shouldReceive('getLine')->once()->with($query)->andReturn($lineContent);
    $this->formatter->shouldReceive('getHeader')->once()->withNoArgs()->andReturn('-- header');
    setConfig('queries.include_pattern', '#.*#i');
    $this->filename->shouldReceive('getLogfile')->times(2)->withNoArgs()->andReturn($expectedFileName);
    expect($this->directory.'/'.$expectedFileName)->toBeFile();
    $this->writer->writeQuery($query);
    expect($this->filesystem->allFiles($this->directory))->toHaveCount(1)
        ->and($this->directory.'/'.$expectedFileName)->toBeFile()
        ->and(file_get_contents($this->directory.'/'.$expectedFileName))->toBe($expectedContent);
});

it('replaces current file content for 1st query when overriding is turned on', function () {
    $expectedFileName = $this->now->format('Y-m').'-log.sql';
    mkdir($this->directory, 0777, true);
    $initialContent = "Initial file content\n";
    file_put_contents($this->directory.'/'.$expectedFileName, $initialContent);

    $lineContent = 'Sample log line';
    $expectedContent = "-- header\nSample log line\n";

    $query = new SqlQuery(1, 'test', 5.41);
    $this->formatter->shouldReceive('getLine')->once()->with($query)->andReturn($lineContent);
    $this->formatter->shouldReceive('getHeader')->once()->withNoArgs()->andReturn('-- header');
    setConfig([
        'queries.include_pattern' => '#.*#i',
        'queries.override_log' => true,
    ]);
    $this->filename->shouldReceive('getLogfile')->times(2)->withNoArgs()->andReturn($expectedFileName);
    expect($this->directory.'/'.$expectedFileName)->toBeFile();
    $this->writer->writeQuery($query);
    expect($this->filesystem->allFiles($this->directory))->toHaveCount(1)
        ->and($this->directory.'/'.$expectedFileName)->toBeFile()
        ->and(file_get_contents($this->directory.'/'.$expectedFileName))->toBe($expectedContent);
});

it('appends to current file content for 2nd query when overriding is turned on', function () {
    $expectedFileName = $this->now->format('Y-m').'-log.sql';
    mkdir($this->directory, 0777, true);
    $initialContent = "Initial file content\n";
    file_put_contents($this->directory.'/'.$expectedFileName, $initialContent);

    $lineContent = 'Sample log line';
    $expectedContent = "-- header\n$lineContent\n$lineContent\n";

    $query1 = new SqlQuery(1, 'test', 5.41);
    $query2 = new SqlQuery(2, 'test', 5.41);
    $this->formatter->shouldReceive('getLine')->twice()->andReturn($lineContent);
    $this->formatter->shouldReceive('getHeader')->once()->withNoArgs()->andReturn('-- header');
    setConfig([
        'queries.include_pattern' => '#.*#i',
        'queries.override_log' => true,
    ]);
    $this->filename->shouldReceive('getLogfile')->times(3)->withNoArgs()->andReturn($expectedFileName);
    expect($this->directory.'/'.$expectedFileName)->toBeFile();
    $this->writer->writeQuery($query1);
    $this->writer->writeQuery($query2);
    expect($this->filesystem->allFiles($this->directory))->toHaveCount(1)
        ->and($this->directory.'/'.$expectedFileName)->toBeFile()
        ->and(file_get_contents($this->directory.'/'.$expectedFileName))->toBe($expectedContent);
});

it('saves select query to file when pattern set to select queries', function () {
    $expectedFileName = $this->now->format('Y-m').'-log.sql';
    $lineContent = 'Sample log line';
    $expectedContent = "\n$lineContent\n";

    $query = new SqlQuery(1, 'select * FROM test', 5.41);
    $this->formatter->shouldReceive('getLine')->once()->with($query)->andReturn($lineContent);
    $this->formatter->shouldReceive('getHeader')->once()->withNoArgs()->andReturn('');
    setConfig('queries.include_pattern', '#^SELECT .*$#i');
    $this->filename->shouldReceive('getLogfile')->twice()->withNoArgs()->andReturn($expectedFileName);
    $this->writer->writeQuery($query);
    expect($this->directory)->toBeFile()
        ->and($this->filesystem->allFiles($this->directory))->toHaveCount(1)
        ->and($this->directory.'/'.$expectedFileName)->toBeFile()
        ->and(file_get_contents($this->directory.'/'.$expectedFileName))->toBe($expectedContent);
});

it('doesnt save select query to file when pattern set to insert or update queries', function () {
    $query = new SqlQuery(1, 'select * FROM test', 5.41);
    setConfig('queries.include_pattern', '#^(?:UPDATE|INSERT) .*$#i');
    $this->writer->writeQuery($query);
    expect($this->directory)->toBeFile()
        ->and($this->filesystem->allFiles($this->directory))->toHaveCount(0);
});

it('saves insert query to file when pattern set to insert or update queries', function () {
    $expectedFileName = $this->now->format('Y-m').'-log.sql';
    $lineContent = 'Sample log line';
    $expectedContent = "\n$lineContent\n";

    $query = new SqlQuery(1, 'INSERT INTO test(one, two, three) values(?, ?, ?)', 5.41);
    $this->formatter->shouldReceive('getLine')->once()->with($query)->andReturn($lineContent);
    $this->formatter->shouldReceive('getHeader')->once()->withNoArgs()->andReturn('');
    setConfig('queries.include_pattern', '#^(?:UPDATE|INSERT) .*$#i');
    $this->filename->shouldReceive('getLogfile')->twice()->withNoArgs()->andReturn($expectedFileName);
    $this->writer->writeQuery($query);
    expect($this->directory)->toBeFile()
        ->and($this->filesystem->allFiles($this->directory))->toHaveCount(1)
        ->and($this->directory.'/'.$expectedFileName)->toBeFile()
        ->and(file_get_contents($this->directory.'/'.$expectedFileName))->toBe($expectedContent);
});

it('uses raw query without bindings when using query pattern', function () {
    $expectedFileName = $this->now->format('Y-m').'-log.sql';
    $lineContent = 'Sample log line';
    $expectedContent = "\n$lineContent\n";

    $query = new SqlQuery(1, 'UPDATE test SET x = 2 WHERE id = 3', 5.41);
    $this->formatter->shouldReceive('getLine')->once()->with($query)->andReturn($lineContent);
    $this->formatter->shouldReceive('getHeader')->once()->withNoArgs()->andReturn('');
    setConfig('queries.include_pattern', '#^(?:UPDATE test SET x = \d |INSERT ).*$#i');
    $this->filename->shouldReceive('getLogfile')->twice()->withNoArgs()->andReturn($expectedFileName);
    $this->writer->writeQuery($query);
    expect($this->directory)->toBeFile()
        ->and($this->filesystem->allFiles($this->directory))->toHaveCount(1)
        ->and($this->directory.'/'.$expectedFileName)->toBeFile()
        ->and(file_get_contents($this->directory.'/'.$expectedFileName))->toBe($expectedContent);
});

it('only logs slow queries', function () {
    $query1 = new SqlQuery(1, 'test1', 5.41);
    $query2 = new SqlQuery(2, 'test2', 500.5);

    setConfig('queries.min_exec_time', 500);

    $this->formatter->shouldReceive('getHeader')->once()->withNoArgs()->andReturn('');
    $this->formatter->shouldReceive('getLine')->once()->with($query2)->andReturn('');

    $writer = Mockery::mock(Writer::class, [$this->formatter, $this->config, $this->filename])
        ->makePartial()->shouldAllowMockingProtectedMethods();
    $writer->shouldAllowMockingProtectedMethods();
    $writer->shouldReceive('writeLine')->twice()->andReturn(false);

    expect($writer->writeQuery($query1))->toBeFalse()
        ->and($writer->writeQuery($query2))->toBeTrue();
});

it('respects query patterns', function () {
    $query1 = new SqlQuery(1, 'select foo from bar', 5.41);
    $query2 = new SqlQuery(2, 'update bar set foo = 1', 3.55);
    $query3 = new SqlQuery(3, "update bar set last_visit = '2021-06-03 10:26:00'", 3.22);

    setConfig([
        'queries.include_pattern' => '/^(?!SELECT).*$/i',
        'queries.exclude_pattern' => '/^UPDATE.*last_visit/i',
    ]);

    $this->formatter->shouldReceive('getHeader')->once()->withNoArgs()->andReturn('');
    $this->formatter->shouldReceive('getLine')->once()->with($query2)->andReturn('');

    $writer = Mockery::mock(Writer::class, [$this->formatter, $this->config, $this->filename])
        ->makePartial()->shouldAllowMockingProtectedMethods();
    $writer->shouldAllowMockingProtectedMethods();
    $writer->shouldReceive('writeLine')->twice()->andReturn(false);

    expect($writer->writeQuery($query1))->toBeFalse()
        ->and($writer->writeQuery($query2))->toBeTrue()
        ->and($writer->writeQuery($query3))->toBeFalse();
});
