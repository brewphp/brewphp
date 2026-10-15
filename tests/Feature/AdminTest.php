<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Feature;

use Apps\Demo\Models\Tag;
use Apps\Demo\Note;
use BrewPHP\Admin\AdminSite;
use BrewPHP\Admin\Models\LogEntry;
use BrewPHP\Auth\Models\Group;
use BrewPHP\Auth\Models\User;
use BrewPHP\Console\Output;
use BrewPHP\Http\Response;
use BrewPHP\Tests\AuthTestCase;

final class AdminTest extends AuthTestCase
{
    protected function settings(): array
    {
        $settings = parent::settings();
        $settings['INSTALLED_APPS'][] = 'brewphp.admin';
        $settings['ROOT_URLCONF'] = 'config/admin_urls.php';

        return $settings;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $editor = $this->app->db()->schemaEditor();
        $editor->createModel(Tag::class);
        $editor->createModel(Note::class);
    }

    private function superuser(): User
    {
        return User::createSuperuser('root', 'root@example.com', 'root password 123');
    }

    private function staff(string ...$permissions): User
    {
        $user = $this->user('staff', 'staff password 123', is_staff: true);
        if ($permissions !== []) {
            $user->grant(...$permissions);
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function post(string $url, array $data = []): Response
    {
        return $this->client->post($url, [...$data, 'csrfmiddlewaretoken' => $this->client->csrfToken()]);
    }

    public function testModelsAreRegistered(): void
    {
        $registry = AdminSite::default()->registry();

        self::assertArrayHasKey(Tag::class, $registry);
        self::assertArrayHasKey(Note::class, $registry);
        self::assertArrayHasKey(User::class, $registry);
        self::assertArrayHasKey(Group::class, $registry);
        self::assertInstanceOf(\Apps\Demo\TagAdmin::class, $registry[Tag::class]);
    }

    public function testOnlyStaffGetIn(): void
    {
        self::assertSame('/admin/login/?next=%2Fadmin%2F', $this->client->get('/admin/')->getHeader('Location'));
        self::assertSame(302, $this->client->get('/admin/demo/tag/')->status());

        $this->user('visitor', 'visitor password 1');
        $this->client->get('/admin/login/');
        $response = $this->post('/admin/login/', ['username' => 'visitor', 'password' => 'visitor password 1']);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Please enter the correct username and password for a staff account.', $response->content());
        self::assertSame(302, $this->client->get('/admin/')->status());

        $this->superuser();
        $response = $this->post('/admin/login/', ['username' => 'root', 'password' => 'root password 123', 'next' => '/admin/demo/tag/']);
        self::assertSame('/admin/demo/tag/', $response->getHeader('Location'));
        $index = $this->client->get('/admin/');
        self::assertSame(200, $index->status());
        self::assertStringContainsString('no-store', (string) $index->getHeader('Cache-Control'));
        self::assertStringContainsString('Welcome, <strong>root</strong>.', $index->content());
        foreach (['Demo', 'Notes', 'Tags', 'Authentication and authorization', 'Users', 'Groups'] as $text) {
            self::assertStringContainsString($text, $index->content());
        }
    }

    public function testLoginIgnoresUnsafeNext(): void
    {
        $this->superuser();
        $this->client->get('/admin/login/');
        $response = $this->post('/admin/login/', ['username' => 'root', 'password' => 'root password 123', 'next' => 'https://evil.example/']);

        self::assertSame('/admin/', $response->getHeader('Location'));
    }

    public function testLogoutIsPostOnly(): void
    {
        $this->client->login($this->superuser());

        self::assertSame(405, $this->client->get('/admin/logout/')->status());
        self::assertSame('/admin/login/', $this->post('/admin/logout/')->getHeader('Location'));
        self::assertStringContainsString('You have been logged out.', $this->client->get('/admin/login/')->content());
        self::assertSame(302, $this->client->get('/admin/')->status());
    }

    public function testPermissionsShapeWhatStaffSee(): void
    {
        $this->client->login($this->staff());
        self::assertStringContainsString('You don’t have permission to view or edit anything.', $this->client->get('/admin/')->content());
        self::assertSame(403, $this->client->get('/admin/demo/tag/')->status());
        self::assertSame(404, $this->client->get('/admin/demo/')->status());

        $tag = Tag::create(['slug' => 'tea']);
        $staff = User::objects()->get(username: 'staff');
        $staff->grant('demo.view_tag');
        $index = $this->client->get('/admin/')->content();
        self::assertStringContainsString('Tags', $index);
        self::assertStringNotContainsString('Notes', $index);

        $list = $this->client->get('/admin/demo/tag/');
        self::assertSame(200, $list->status());
        self::assertStringNotContainsString('Add tag', $list->content());
        self::assertStringNotContainsString('name="action"', $list->content(), 'No actions without change or delete permission.');
        self::assertSame(403, $this->client->get('/admin/demo/tag/add/')->status());

        // View only: no form fields, and saving is refused.
        $change = $this->client->get('/admin/demo/tag/' . $tag->pk . '/change/');
        self::assertSame(200, $change->status());
        self::assertStringContainsString('View tag', $change->content());
        self::assertStringNotContainsString('name="slug"', $change->content());
        self::assertSame(403, $this->post('/admin/demo/tag/' . $tag->pk . '/change/', ['slug' => 'hacked', '_save' => '1'])->status());
        self::assertSame(403, $this->client->get('/admin/demo/tag/' . $tag->pk . '/delete/')->status());
        $tag->refresh();
        self::assertSame('tea', $tag->slug);
    }

    public function testAddChangeDeleteAndHistory(): void
    {
        $root = $this->superuser();
        $this->client->login($root);

        $form = $this->client->get('/admin/demo/tag/add/');
        self::assertStringContainsString('name="slug"', $form->content());

        $response = $this->post('/admin/demo/tag/add/', ['slug' => 'bad slug!', '_save' => '1']);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Please correct the error below.', $response->content());

        $response = $this->post('/admin/demo/tag/add/', ['slug' => 'coffee', '_continue' => '1']);
        $tag = Tag::objects()->get(slug: 'coffee');
        self::assertSame('/admin/demo/tag/' . $tag->pk . '/change/', $response->getHeader('Location'));
        self::assertStringContainsString('was added successfully. You may edit it again below.', $this->client->get((string) $response->getHeader('Location'))->content());

        $response = $this->post('/admin/demo/tag/' . $tag->pk . '/change/', ['slug' => 'espresso', '_save' => '1']);
        self::assertSame('/admin/demo/tag/', $response->getHeader('Location'));
        $tag->refresh();
        self::assertSame('espresso', $tag->slug);

        $history = $this->client->get('/admin/demo/tag/' . $tag->pk . '/history/')->content();
        self::assertStringContainsString('Added.', $history);
        self::assertStringContainsString('Changed Slug.', $history);
        self::assertSame([LogEntry::ADDITION, LogEntry::CHANGE], LogEntry::objects()->orderBy('id')->valuesList('action_flag', flat: true));

        Note::create(['text' => 'with milk', 'tag' => $tag]);
        $confirm = $this->client->get('/admin/demo/tag/' . $tag->pk . '/delete/');
        self::assertStringContainsString('Are you sure you want to delete the tag', $confirm->content());
        self::assertStringContainsString('<strong>Notes</strong>', $confirm->content(), 'Cascaded objects are listed.');
        self::assertStringContainsString('<li>Note object (', $confirm->content());

        self::assertSame('/admin/demo/tag/', $this->post('/admin/demo/tag/' . $tag->pk . '/delete/')->getHeader('Location'));
        self::assertSame(0, Tag::objects()->count());
        self::assertSame(0, Note::objects()->count());
        self::assertStringContainsString('My actions', $this->client->get('/admin/')->content());
        self::assertSame(404, $this->client->get('/admin/demo/tag/999/change/')->status());
        self::assertSame(404, $this->client->get('/admin/demo/nope/')->status());
    }

    public function testChangeList(): void
    {
        $this->client->login($this->superuser());
        $tea = Tag::create(['slug' => 'tea']);
        $coffee = Tag::create(['slug' => 'coffee']);
        Note::create(['text' => 'green', 'tag' => $tea]);
        Note::create(['text' => 'black', 'tag' => $tea]);
        Note::create(['text' => 'latte', 'tag' => $coffee]);

        $page = $this->client->get('/admin/demo/note/')->content();
        self::assertStringContainsString('3 notes', $page);
        self::assertStringContainsString('By tag', $page);
        self::assertStringContainsString('black', $page);
        self::assertStringNotContainsString('latte', $page, 'Two notes per page.');
        self::assertStringContainsString('latte', $this->client->get('/admin/demo/note/?p=2')->content());

        $filtered = $this->client->get('/admin/demo/note/?tag__exact=' . $coffee->pk)->content();
        self::assertStringContainsString('latte', $filtered);
        self::assertStringNotContainsString('green', $filtered);

        $search = $this->client->get('/admin/demo/note/?q=tea')->content();
        self::assertStringContainsString('2 results', $search);
        self::assertStringContainsString('green', $search);

        // Undeclared filters and sort columns are ignored.
        self::assertStringContainsString('3 notes', $this->client->get('/admin/demo/note/?text__startswith=x&o=id')->content());

        $tags = $this->client->get('/admin/demo/tag/?o=-slug')->content();
        self::assertLessThan(strpos($tags, '>coffee<'), strpos($tags, '>tea<'));
        self::assertStringContainsString('<td>2</td>', $tags, 'Method columns work.');
    }

    public function testActions(): void
    {
        $this->client->login($this->superuser());
        $tea = Tag::create(['slug' => 'tea']);
        $coffee = Tag::create(['slug' => 'coffee']);

        $this->post('/admin/demo/tag/', ['action' => 'shout', '_selected_action' => [(string) $tea->pk]]);
        $tea->refresh();
        $coffee->refresh();
        self::assertSame('TEA', $tea->slug);
        self::assertSame('coffee', $coffee->slug);

        $this->post('/admin/demo/tag/', ['action' => 'no_such_action', '_selected_action' => [(string) $coffee->pk]]);
        $this->post('/admin/demo/tag/', ['action' => 'shout']);
        $coffee->refresh();
        self::assertSame('coffee', $coffee->slug);

        $confirm = $this->post('/admin/demo/tag/', ['action' => 'delete_selected', '_selected_action' => [(string) $tea->pk, (string) $coffee->pk]]);
        self::assertStringContainsString('Are you sure you want to delete the selected tags?', $confirm->content());
        self::assertSame(2, Tag::objects()->count());

        $this->post('/admin/demo/tag/', ['action' => 'delete_selected', '_selected_action' => [(string) $tea->pk, (string) $coffee->pk], 'post' => 'yes']);
        self::assertSame(0, Tag::objects()->count());
        self::assertStringContainsString('Successfully deleted 2 tags.', $this->client->get('/admin/demo/tag/')->content());
    }

    public function testRowsOutsideTheQuerysetAreUnreachable(): void
    {
        $this->client->login($this->staff('demo.view_note', 'demo.change_note', 'demo.delete_note'));
        $public = Note::create(['text' => 'public note']);
        $secret = Note::create(['text' => 'secret plans']);

        self::assertStringNotContainsString('secret plans', $this->client->get('/admin/demo/note/')->content());
        self::assertSame(404, $this->client->get('/admin/demo/note/' . $secret->pk . '/change/')->status());
        self::assertSame(404, $this->post('/admin/demo/note/' . $secret->pk . '/delete/')->status());

        $this->post('/admin/demo/note/', ['action' => 'delete_selected', '_selected_action' => [(string) $public->pk, (string) $secret->pk], 'post' => 'yes']);
        self::assertSame(['secret plans'], Note::objects()->valuesList('text', flat: true));
    }

    public function testDeletingRelatedObjectsNeedsTheirPermission(): void
    {
        $this->client->login($this->staff('demo.view_tag', 'demo.delete_tag'));
        $tag = Tag::create(['slug' => 'tea']);
        Note::create(['text' => 'cascades', 'tag' => $tag]);

        $page = $this->client->get('/admin/demo/tag/' . $tag->pk . '/delete/')->content();
        self::assertStringContainsString('doesn’t have permission to delete the following types of objects', $page);
        $this->post('/admin/demo/tag/' . $tag->pk . '/delete/');
        self::assertSame(1, Tag::objects()->count());
    }

    public function testValuesAreEscaped(): void
    {
        $this->client->login($this->superuser());
        $note = Note::create(['text' => '<script>alert(1)</script>']);

        foreach (['/admin/demo/note/', '/admin/demo/note/' . $note->pk . '/change/', '/admin/demo/note/?q=%3Cscript%3E'] as $url) {
            $content = $this->client->get($url)->content();
            self::assertStringNotContainsString('<script>alert(1)</script>', $content, $url);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $content, $url);
        }
    }

    public function testFormsNeedACsrfToken(): void
    {
        $this->client->login($this->superuser());

        self::assertSame(403, $this->client->post('/admin/demo/tag/add/', ['slug' => 'x'])->status());
        self::assertSame(0, Tag::objects()->count());
    }

    public function testStaffCannotEscalateTheirOwnRights(): void
    {
        $root = $this->superuser();
        $staff = $this->staff('auth.view_user', 'auth.change_user');
        $this->client->login($staff);

        $list = $this->client->get('/admin/auth/user/')->content();
        self::assertStringContainsString('>staff<', $list);
        self::assertStringNotContainsString('>root<', $list, 'Superusers are hidden from other staff.');
        self::assertSame(404, $this->client->get('/admin/auth/user/' . $root->pk . '/change/')->status());

        $form = $this->client->get('/admin/auth/user/' . $staff->pk . '/change/')->content();
        self::assertStringNotContainsString('name="is_superuser"', $form);
        self::assertStringNotContainsString('name="groups[]"', $form);
        self::assertStringContainsString('Stored as a bcrypt_sha256 hash.', $form);
        self::assertStringNotContainsString((string) $staff->password, $form);

        $this->post('/admin/auth/user/' . $staff->pk . '/change/', [
            'username' => 'staff', 'is_active' => 'on', 'is_staff' => 'on', 'is_superuser' => 'on', '_save' => '1',
        ]);
        $staff->refresh();
        self::assertFalse($staff->isSuperuser());

        // A superuser can manage groups and permissions.
        $editors = Group::create(['name' => 'Editors']);
        $this->client->login($root);
        self::assertStringContainsString('name="groups[]"', $this->client->get('/admin/auth/user/' . $staff->pk . '/change/')->content());
        $this->post('/admin/auth/user/' . $staff->pk . '/change/', [
            'username' => 'staff', 'is_active' => 'on', 'is_staff' => 'on', 'groups' => [(string) $editors->pk], '_save' => '1',
        ]);
        self::assertSame(['Editors'], $staff->groups()->valuesList('name', flat: true));
    }

    public function testAddingAUserAsksForAPassword(): void
    {
        $this->client->login($this->superuser());

        $response = $this->post('/admin/auth/user/add/', ['username' => 'newbie', 'password1' => 'a fine passphrase', 'password2' => 'different', '_save' => '1']);
        self::assertStringContainsString('The two password fields didn’t match.', $response->content());

        $this->post('/admin/auth/user/add/', ['username' => 'newbie', 'password1' => 'a fine passphrase', 'password2' => 'a fine passphrase', '_save' => '1']);
        $user = User::objects()->get(username: 'newbie');
        self::assertTrue($user->checkPassword('a fine passphrase'));
        self::assertFalse($user->isStaff());
    }

    public function testStaticFiles(): void
    {
        $css = $this->client->get('/admin/static/admin.css');
        self::assertSame(200, $css->status());
        self::assertSame('text/css; charset=utf-8', $css->getHeader('Content-Type'));
        self::assertSame(200, $this->client->get('/admin/static/admin.js')->status());
        self::assertSame(404, $this->client->get('/admin/static/settings.php')->status());
        self::assertSame(404, $this->client->get('/admin/static/..%2F..%2Fcomposer.json')->status());
    }

    public function testPasswordChange(): void
    {
        $this->client->login($this->superuser());
        $this->client->get('/admin/password_change/');
        $response = $this->post('/admin/password_change/', ['old_password' => 'root password 123', 'new_password1' => 'another long one', 'new_password2' => 'another long one']);

        self::assertSame('/admin/password_change/done/', $response->getHeader('Location'));
        self::assertSame(200, $this->client->get('/admin/')->status());
    }

    public function testSystemChecks(): void
    {
        $this->app->settings()->set('MIDDLEWARE', []);
        $output = Output::buffered();
        $this->app->console()->run(['manage.php', 'check'], $output);
        $text = $output->fetch();

        self::assertStringContainsString('admin.E002', $text);
        self::assertStringContainsString('admin.E003', $text);
        self::assertStringContainsString('admin.E004', $text);
    }
}
