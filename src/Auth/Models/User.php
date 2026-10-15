<?php

declare(strict_types=1);

namespace BrewPHP\Auth\Models;

use BrewPHP\Auth\Authenticatable;
use BrewPHP\Auth\Passwords;
use BrewPHP\Core\AltersData;
use BrewPHP\Core\Application;
use BrewPHP\Core\ValidationError;
use BrewPHP\Db\Fields\BooleanField;
use BrewPHP\Db\Fields\CharField;
use BrewPHP\Db\Fields\DateTimeField;
use BrewPHP\Db\Fields\EmailField;
use BrewPHP\Db\Model;
use BrewPHP\Db\QuerySet;

/**
 * A user account (table `auth_user`), like Django's `User`.
 *
 *     $ann = User::createUser('ann', 'ann@example.com', 'a long passphrase');
 *     $ann->checkPassword('a long passphrase');   // true
 *     $ann->hasPerm('blog.add_post');
 *     $ann->addToGroup(Group::objects()->get(name: 'Editors'));
 *
 * Passwords are never stored: `password` holds a salted, slow hash (see
 * Passwords). Use setPassword(), never assign `password` directly.
 *
 * @property ?int $id
 * @property string $username
 * @property string $password
 * @property string $email
 * @property string $first_name
 * @property string $last_name
 * @property bool $is_active
 * @property bool $is_staff
 * @property bool $is_superuser
 * @property ?\DateTimeImmutable $last_login
 * @property \DateTimeImmutable $date_joined
 */
class User extends Model implements Authenticatable
{
    /** The backend that authenticated this user (set by Auth). */
    public ?string $authBackend = null;

    /** @var list<string>|null cached "app.codename" permissions */
    private ?array $permissionCache = null;

    public static function fields(): array
    {
        return [
            'password' => new CharField(maxLength: 255, editable: false),
            'last_login' => new DateTimeField(null: true, blank: true, editable: false),
            'is_superuser' => new BooleanField(default: false, verboseName: 'superuser status', helpText: 'Designates that this user has all permissions without explicitly assigning them.'),
            'username' => new CharField(
                maxLength: 150,
                unique: true,
                helpText: 'Required. 150 characters or fewer. Letters, digits and @/./+/-/_ only.',
                validators: [self::validateUsername(...)],
            ),
            'first_name' => new CharField(maxLength: 150, blank: true),
            'last_name' => new CharField(maxLength: 150, blank: true),
            'email' => new EmailField(blank: true, verboseName: 'email address'),
            'is_staff' => new BooleanField(default: false, verboseName: 'staff status', helpText: 'Designates whether the user can log into the admin site.'),
            'is_active' => new BooleanField(default: true, verboseName: 'active', helpText: 'Unselect this instead of deleting accounts.'),
            'date_joined' => new DateTimeField(default: static fn (): \DateTimeImmutable => new \DateTimeImmutable()),
        ];
    }

    public static function ordering(): array
    {
        return ['username'];
    }

    /**
     * Letters, digits and @ . + - _ only.
     */
    public static function validateUsername(mixed $value): void
    {
        if (is_string($value) && preg_match('/^[\w.@+-]+$/u', $value) !== 1) {
            throw new ValidationError('Enter a valid username. This value may contain only letters, numbers, and @/./+/-/_ characters.', 'invalid_username');
        }
    }

    // Creating users ------------------------------------------------------------

    /**
     * Create and save a user. Without a password, the account cannot log in
     * with one.
     *
     * @param array<string, mixed> $extra other fields
     */
    public static function createUser(string $username, string $email = '', ?string $password = null, array $extra = []): static
    {
        $user = new static(['username' => trim($username), 'email' => self::normalizeEmail($email), ...$extra]);
        $password === null ? $user->setUnusablePassword() : $user->setPassword($password);
        $user->fullClean(['password']);
        $user->save();

        return $user;
    }

    /**
     * Create and save a staff superuser.
     *
     * @param array<string, mixed> $extra
     */
    public static function createSuperuser(string $username, string $email = '', ?string $password = null, array $extra = []): static
    {
        return static::createUser($username, $email, $password, ['is_staff' => true, 'is_superuser' => true, ...$extra]);
    }

    /**
     * The domain part of an email address in lower case.
     */
    public static function normalizeEmail(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');

        return $at === false ? $email : substr($email, 0, $at) . strtolower(substr($email, $at));
    }

    // Passwords -----------------------------------------------------------------

    /**
     * Hash and set a password (call save() afterwards).
     */
    #[AltersData]
    public function setPassword(string $password): void
    {
        $this->password = Passwords::make($password);
        $this->permissionCache = null;
    }

    /**
     * Whether the password is right. A hash made with older settings is
     * upgraded (and saved) when the password matches.
     */
    #[AltersData]
    public function checkPassword(string $password): bool
    {
        $hash = $this->getAttribute('password');
        if (!is_string($hash) || !Passwords::check($password, $hash)) {
            return false;
        }
        if (Passwords::needsRehash($hash)) {
            $this->setPassword($password);
            if ($this->isPersisted()) {
                static::objects()->filter(pk: $this->pk)->update(['password' => $this->password]);
            }
        }

        return true;
    }

    #[AltersData]
    public function setUnusablePassword(): void
    {
        $this->password = Passwords::unusable();
    }

    public function hasUsablePassword(): bool
    {
        $hash = $this->getAttribute('password');

        return is_string($hash) && Passwords::isUsable($hash);
    }

    /**
     * A value that changes whenever the password changes. Stored in the
     * session at login: changing the password logs out every other session.
     */
    public function sessionAuthHash(): string
    {
        $key = Application::hasInstance() ? Application::getInstance()->settings()->string('SECRET_KEY') : '';

        return hash_hmac('sha256', (string) $this->getAttribute('password'), 'brewphp.auth.session' . $key);
    }

    // Authenticatable -----------------------------------------------------------

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function isAnonymous(): bool
    {
        return false;
    }

    public function isActive(): bool
    {
        return (bool) $this->getAttribute('is_active');
    }

    public function isStaff(): bool
    {
        return (bool) $this->getAttribute('is_staff');
    }

    public function isSuperuser(): bool
    {
        return (bool) $this->getAttribute('is_superuser');
    }

    public function getUsername(): string
    {
        return (string) $this->getAttribute('username');
    }

    public function getFullName(): string
    {
        return trim($this->getAttribute('first_name') . ' ' . $this->getAttribute('last_name'));
    }

    public function getShortName(): string
    {
        return (string) $this->getAttribute('first_name');
    }

    /**
     * Active superusers have every permission; inactive users have none.
     */
    public function hasPerm(string $permission): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->isSuperuser() || in_array($permission, $this->getAllPermissions(), true);
    }

    public function hasPerms(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPerm($permission)) {
                return false;
            }
        }

        return true;
    }

    public function hasModulePerms(string $appLabel): bool
    {
        if (!$this->isActive()) {
            return false;
        }
        if ($this->isSuperuser()) {
            return true;
        }
        foreach ($this->getAllPermissions() as $permission) {
            if (str_starts_with($permission, $appLabel . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The user's own permissions and those of their groups.
     */
    public function getAllPermissions(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }
        if (!$this->isPersisted() || !$this->isActive()) {
            return $this->permissionCache = [];
        }
        $direct = Permission::objects()->filter(user_links__user: $this->pk)->valuesList('app_label', 'codename');
        $viaGroups = Permission::objects()->filter(group_links__group__user_links__user: $this->pk)->valuesList('app_label', 'codename');
        $permissions = [];
        foreach ([...$direct, ...$viaGroups] as $row) {
            if (is_array($row)) {
                $permissions[$row[0] . '.' . $row[1]] = true;
            }
        }
        $names = array_keys($permissions);
        sort($names);

        return $this->permissionCache = $names;
    }

    // Groups and permissions ----------------------------------------------------

    /**
     * @return QuerySet<Group>
     */
    public function groups(): QuerySet
    {
        return Group::objects()->filter(user_links__user: $this->pk);
    }

    /**
     * The permissions given to the user directly (not through groups).
     *
     * @return QuerySet<Permission>
     */
    public function userPermissions(): QuerySet
    {
        return Permission::objects()->filter(user_links__user: $this->pk);
    }

    #[AltersData]
    public function addToGroup(Group ...$groups): void
    {
        foreach ($groups as $group) {
            UserGroup::objects()->getOrCreate(['user' => $this, 'group' => $group]);
        }
        $this->permissionCache = null;
    }

    #[AltersData]
    public function removeFromGroup(Group ...$groups): void
    {
        foreach ($groups as $group) {
            UserGroup::objects()->filter(user: $this->pk, group: $group->pk)->delete();
        }
        $this->permissionCache = null;
    }

    /**
     * Make the user's groups exactly these.
     *
     * @param iterable<Group> $groups
     */
    #[AltersData]
    public function setGroups(iterable $groups): void
    {
        $ids = [];
        foreach ($groups as $group) {
            $ids[] = $group->pk;
            UserGroup::objects()->getOrCreate(['user' => $this, 'group' => $group]);
        }
        $stale = UserGroup::objects()->filter(user: $this->pk);
        if ($ids !== []) {
            $stale = $stale->exclude(group__in: $ids);
        }
        $stale->delete();
        $this->permissionCache = null;
    }

    /**
     * Give the user permissions directly: Permission objects or "app.codename".
     */
    #[AltersData]
    public function grant(Permission|string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            UserPermission::objects()->getOrCreate(['user' => $this, 'permission' => Permission::resolve($permission)]);
        }
        $this->permissionCache = null;
    }

    #[AltersData]
    public function revoke(Permission|string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            UserPermission::objects()->filter(user: $this->pk, permission: Permission::resolve($permission)->pk)->delete();
        }
        $this->permissionCache = null;
    }

    /**
     * Make the user's own permissions exactly these.
     *
     * @param iterable<Permission> $permissions
     */
    #[AltersData]
    public function setPermissions(iterable $permissions): void
    {
        $ids = [];
        foreach ($permissions as $permission) {
            $ids[] = $permission->pk;
            UserPermission::objects()->getOrCreate(['user' => $this, 'permission' => $permission]);
        }
        $stale = UserPermission::objects()->filter(user: $this->pk);
        if ($ids !== []) {
            $stale = $stale->exclude(permission__in: $ids);
        }
        $stale->delete();
        $this->permissionCache = null;
    }

    public function __toString(): string
    {
        return $this->getUsername();
    }
}
