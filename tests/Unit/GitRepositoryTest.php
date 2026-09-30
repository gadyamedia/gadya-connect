<?php

namespace Gadya\Connect\Tests\Unit;

use Gadya\Connect\Report\GitRepository;
use Gadya\Connect\Report\ReportBuilder;
use Gadya\Connect\Tests\TestCase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The check-in's `app.repository`, read from the origin remote in .git/config.
 */
class GitRepositoryTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function remotes(): array
    {
        return [
            'https' => ['https://github.com/gadyamedia/funonus.git', 'gadyamedia/funonus'],
            'https without .git' => ['https://github.com/gadyamedia/funonus', 'gadyamedia/funonus'],
            'https with a token' => ['https://x-access-token:secret@github.com/gadyamedia/funonus.git', 'gadyamedia/funonus'],
            'scp style ssh' => ['git@github.com:gadyamedia/funonus.git', 'gadyamedia/funonus'],
            'scp style without .git' => ['git@github.com:gadyamedia/funonus', 'gadyamedia/funonus'],
            'ssh url' => ['ssh://git@github.com/gadyamedia/funonus.git', 'gadyamedia/funonus'],
            'dots and dashes in the name' => ['git@github.com:gadya-media/site.example.com.git', 'gadya-media/site.example.com'],
            'another host' => ['git@gitlab.com:gadyamedia/funonus.git', null],
            'a lookalike host' => ['https://notgithub.com/gadyamedia/funonus.git', null],
            'a local path' => ['/srv/git/funonus.git', null],
            'no repository' => ['https://github.com/gadyamedia', null],
        ];
    }

    #[DataProvider('remotes')]
    public function test_a_remote_is_normalised_to_owner_and_name(string $url, ?string $expected): void
    {
        $this->assertSame($expected, GitRepository::normalise($url));
    }

    public function test_the_origin_remote_is_read_from_the_git_config(): void
    {
        $path = $this->config("[core]\n\trepositoryformatversion = 0\n[remote \"upstream\"]\n\turl = git@github.com:someone/else.git\n[remote \"origin\"]\n\turl = git@github.com:gadyamedia/funonus.git\n\tfetch = +refs/heads/*:refs/remotes/origin/*\n[branch \"main\"]\n\tremote = origin\n");

        $this->assertSame('gadyamedia/funonus', GitRepository::origin($path));
    }

    public function test_nothing_is_reported_without_a_git_config_or_an_origin(): void
    {
        $this->assertNull(GitRepository::origin('/nonexistent/.git/config'));
        $this->assertNull(GitRepository::origin(sys_get_temp_dir()), 'A directory is not a config file.');
        $this->assertNull(GitRepository::origin($this->config("[core]\n\tbare = false\n")));
        $this->assertNull(GitRepository::origin($this->config("[remote \"origin\"]\n\turl = git@gitlab.com:a/b.git\n")));
    }

    public function test_the_check_in_carries_the_repository_and_survives_a_site_without_one(): void
    {
        $base = sys_get_temp_dir().'/gadya-connect-site-'.uniqid();
        File::ensureDirectoryExists($base.'/.git');
        $this->app->setBasePath($base);

        $this->assertNull(app(ReportBuilder::class)->build()['app']['repository']);

        file_put_contents($base.'/.git/config', "[remote \"origin\"]\n\turl = https://github.com/gadyamedia/funonus.git\n");

        $this->assertSame('gadyamedia/funonus', app(ReportBuilder::class)->build()['app']['repository']);

        File::deleteDirectory($base);
    }

    private function config(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gitconfig');
        file_put_contents($path, $contents);

        return $path;
    }
}
