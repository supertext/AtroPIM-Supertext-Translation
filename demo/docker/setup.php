<?php

/**
 * Demo only (runs on every start, as www-data). Creates what is missing and never changes what
 * exists:
 *
 *   php setup.php model     Swiss German, French and Italian as additional languages and a
 *                           multilingual product attribute ("Tasting notes")
 *   php setup.php content   sample products, the Supertext connection and action, the editor
 *                           role, the DEMO_* accounts
 *
 * Two processes, because new languages and attributes change the metadata the content needs.
 */

declare(strict_types=1);

chdir('/var/www/atro');
set_include_path('/var/www/atro');
require 'vendor/autoload.php';
require __DIR__ . '/common.php';

use Atro\Core\Container;
use Atro\Core\UserContext;

const DEMO_LANGUAGES = [
    'de_CH' => 'Deutsch (Schweiz)',
    'fr_CH' => 'Français (Suisse)',
    'it_CH' => 'Italiano (Svizzera)',
];

const DEMO_PRODUCTS = [
    'praline-box-16' => [
        'name'            => 'Handmade praline box, 16 pieces',
        'description'     => "Sixteen pralines from our Bern workshop, filled with hazelnut, caramel and dark ganache.\nA gift box for every occasion.",
        'longDescription' => '<p>Every praline is filled and decorated <strong>by hand</strong> in our Bern workshop, with Swiss milk and cocoa from <a href="https://www.supertext.com">our fair trade partners</a>.</p><ul><li>Gluten-free</li><li>Keeps for six weeks at 15 to 18 °C</li></ul>',
        'tastingNotes'    => 'Hazelnut, caramel and a hint of sea salt.',
    ],
    'dark-chocolate-72' => [
        'name'            => 'Dark chocolate bar, 72% cocoa',
        'description'     => 'A dark chocolate bar with notes of red berries, made from single-origin cocoa from Ecuador.',
        'longDescription' => '<p>We roast the cocoa beans <strong>slowly</strong> and conch the chocolate for 72 hours, which gives it its smooth texture.</p>',
        'tastingNotes'    => 'Red berries, roasted nuts and a long, dry finish.',
    ],
];

/** What the editor role may do: translate products, read what the product form shows. */
const DEMO_EDITOR_SCOPES = [
    'Product'        => ['create' => 'yes', 'read' => 'all', 'edit' => 'all', 'delete' => 'no', 'attributeValues' => 'yes'],
    'Category'       => ['create' => 'no', 'read' => 'all', 'edit' => 'no', 'delete' => 'no'],
    'Brand'          => ['create' => 'no', 'read' => 'all', 'edit' => 'no', 'delete' => 'no'],
    'Classification' => ['create' => 'no', 'read' => 'all', 'edit' => 'no', 'delete' => 'no'],
    'Attribute'      => ['create' => 'no', 'read' => 'all', 'edit' => 'no', 'delete' => 'no'],
    'File'           => ['create' => 'yes', 'read' => 'all', 'edit' => 'all', 'delete' => 'no'],
    'Action'         => ['create' => 'no', 'read' => 'all', 'edit' => 'no', 'delete' => 'no'],
];

const DEMO_ROLE = 'Product editor (Supertext demo)';

$phase = $argv[1] ?? '';
$app   = new \Atro\Core\Application();
$c     = $app->getContainer();

if (!$c->get('config')->get('isInstalled')) {
    demo_log('AtroPIM is not installed yet; skipping the demo setup.');
    exit(0);
}

demo_as_system($c);

try {
    match ($phase) {
        'model'     => demo_model($c),
        'content'   => demo_content($c),
        default     => throw new InvalidArgumentException('Usage: php setup.php model|content'),
    };
} catch (Throwable $e) {
    demo_log('Demo setup failed: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    exit(1);
}

function demo_as_system(Container $c): void
{
    $em   = $c->get('entityManager');
    $user = $em->getRepository('User')->getGlobalSystemUser();
    $em->setUser($user);
    $c->get(UserContext::class)->set($user);
}

function demo_find(Container $c, string $entity, array $where): ?\Espo\ORM\Entity
{
    $found = $c->get('entityManager')->getRepository($entity)->where($where)->findOne();

    return $found instanceof \Espo\ORM\Entity ? $found : null;
}

/** Creates a record through its service (validation, hooks) and returns it. */
function demo_create(Container $c, string $entity, array $data): \Espo\ORM\Entity
{
    $id = $c->get('serviceFactory')->create($entity)->createEntity((object) $data);

    return $c->get('entityManager')->getRepository($entity)->get($id);
}

function demo_model(Container $c): void
{
    $service = $c->get('serviceFactory')->create('Language');

    foreach (DEMO_LANGUAGES as $code => $name) {
        if (demo_find($c, 'Language', ['code' => $code]) === null) {
            $service->createEntity((object) ['code' => $code, 'name' => $name, 'role' => 'additional']);
            demo_log("Added the language $code");
        }
    }

    // A multilingual product attribute, to show that attribute values are translated too.
    if (demo_find($c, 'Attribute', ['systemName' => 'tastingNotes', 'entityId' => 'Product']) === null) {
        demo_create($c, 'Attribute', [
            'name'        => 'Tasting notes',
            'systemName'  => 'tastingNotes',
            'type'        => 'text',
            'isMultilang' => true,
            'entityId'    => 'Product',
            // AtroPIM's default panel for attributes on the product form.
            'attributePanelId' => 'attributeValues',
        ]);
        demo_log('Added the attribute "Tasting notes"');
    }
}

function demo_content(Container $c): void
{
    $services = $c->get('serviceFactory');

    $attribute = demo_find($c, 'Attribute', ['systemName' => 'tastingNotes', 'entityId' => 'Product']);

    if ($attribute === null) {
        throw new RuntimeException('The attribute "Tasting notes" is missing (php setup.php model creates it).');
    }

    $products = $services->create('Product');

    foreach (DEMO_PRODUCTS as $number => $data) {
        if (demo_find($c, 'Product', ['number' => $number]) !== null) {
            continue;
        }

        // The attribute is assigned on create: a second update in this process is ignored.
        $products->createEntity((object) [
            'number'                                => $number,
            'name'                                  => $data['name'],
            'description'                           => $data['description'],
            'longDescription'                       => $data['longDescription'],
            'status'                                => 'draft',
            '__attributes'                          => [$attribute->get('id')],
            (string) $attribute->get('systemName') => $data['tastingNotes'],
        ]);
        demo_log("Added the sample product $number");
    }

    // The Supertext connection (no key: the module uses SUPERTEXT_API_KEY) and the actions.
    $connection = demo_find($c, 'Connection', ['type' => 'supertext']);

    if ($connection === null) {
        $connection = demo_create($c, 'Connection', [
            'name'                 => 'Supertext',
            'type'                 => 'supertext',
            'supertextEnvironment' => 'live',
            'supertextTimeout'     => 180,
        ]);
        demo_log('Added the Supertext connection');
    }

    if (demo_find($c, 'Action', ['type' => 'supertextTranslate']) === null) {
        $services->create('Action')->createEntity((object) [
            'name'                  => 'Translate with Supertext',
            'type'                  => 'supertextTranslate',
            'usage'                 => 'record',
            'sourceEntity'          => 'Product',
            'display'               => 'single',
            'massAction'            => true,
            'executeAs'             => 'sameUser',
            'isActive'              => true,
            'supertextConnectionId' => $connection->get('id'),
        ]);
        demo_log('Added the action "Translate with Supertext"');
    }

    // "Send configuration to Supertext" as a button on the Supertext connection (administrators).
    if (demo_find($c, 'Action', ['type' => 'supertextSendConfiguration']) === null) {
        $services->create('Action')->createEntity((object) [
            'name'         => 'Send configuration to Supertext',
            'type'         => 'supertextSendConfiguration',
            'usage'        => 'record',
            'sourceEntity' => 'Connection',
            'display'      => 'single',
            'massAction'   => false,
            'executeAs'    => 'sameUser',
            'isActive'     => true,
        ]);
        demo_log('Added the action "Send configuration to Supertext"');
    }

    $role = demo_role($c);
    demo_user($c, 'DEMO_ADMIN', true, null);
    demo_user($c, 'DEMO_EDITOR', false, $role->get('id'));
}

function demo_role(Container $c): \Espo\ORM\Entity
{
    $role = demo_find($c, 'Role', ['name' => DEMO_ROLE]);

    if ($role !== null) {
        return $role;
    }

    $services = $c->get('serviceFactory');
    $role     = demo_create($c, 'Role', ['name' => DEMO_ROLE]);

    foreach (DEMO_EDITOR_SCOPES as $scope => $rights) {
        $services->create('RoleScope')->createEntity((object) [
            'roleId'                     => $role->get('id'),
            'name'                       => $scope,
            'hasAccess'                  => true,
            'createAction'               => $rights['create'],
            'readAction'                 => $rights['read'],
            'editAction'                 => $rights['edit'],
            'deleteAction'               => $rights['delete'],
            'streamAction'               => 'all',
            'createAttributeValueAction' => $rights['attributeValues'] ?? 'no',
            'deleteAttributeValueAction' => 'no',
        ]);
    }

    demo_log('Added the role "' . DEMO_ROLE . '"');

    return $role;
}

/** Creates the account if it doesn't exist; an existing account is never changed. */
function demo_user(Container $c, string $prefix, bool $isAdmin, ?string $roleId): void
{
    $config  = $c->get('config');
    $pattern = (string) ($config->get('passwordRegexPattern') ?? DEMO_DEFAULT_PASSWORD_PATTERN);
    $email   = trim((string) getenv($prefix . '_EMAIL'));

    if ($email !== '' && demo_find($c, 'User', ['userName' => $email]) !== null) {
        demo_log("{$prefix}: account exists, left unchanged");

        return;
    }

    [$email, $password] = demo_account($prefix, $pattern);

    if ($email === null) {
        return;
    }

    $data = [
        'userName'        => $email,
        'emailAddress'    => $email,
        'name'            => $isAdmin ? 'Demo Administrator' : 'Demo Editor',
        'firstName'       => 'Demo',
        'lastName'        => $isAdmin ? 'Administrator' : 'Editor',
        'password'        => $password,
        'passwordConfirm' => $password,
        'isActive'        => true,
        'isAdmin'         => $isAdmin,
    ];

    if ($roleId !== null) {
        $data['rolesIds'] = [$roleId];
    }

    try {
        $c->get('serviceFactory')->create('User')->createEntity((object) $data);
        demo_log("Created the account from {$prefix}_EMAIL");
    } catch (Throwable $e) {
        demo_log("Could not create the account from {$prefix}_EMAIL: " . $e->getMessage());
    }
}
