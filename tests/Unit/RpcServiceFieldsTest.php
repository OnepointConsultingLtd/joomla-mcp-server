<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

namespace Joomla\Component\Mcpserver\Tests\Unit;

defined('_JEXEC') or die;

use Joomla\Component\Mcpserver\Administrator\Service\CacheService;
use Joomla\Component\Mcpserver\Administrator\Service\PolicyService;
use Joomla\Component\Mcpserver\Administrator\Service\PromptRegistry;
use Joomla\Component\Mcpserver\Administrator\Service\RestClient;
use Joomla\Component\Mcpserver\Administrator\Service\RpcService;
use Joomla\Component\Mcpserver\Administrator\Service\SchemaValidator;
use Joomla\Component\Mcpserver\Administrator\Service\SimpleArrayCache;
use Joomla\Component\Mcpserver\Administrator\Service\ToolRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the com_fields tool family. Every tool is API-backed, so the REST client is the only
 * seam these tests need — there is no database access to fake.
 */
class RpcServiceFieldsTest extends TestCase
{
    public function testUpdateFieldSendsTheCompleteMergedParams(): void
    {
        $captured = $this->captureFieldPatch(
            [
                'params' => ['showlabel' => 1, 'render_class' => 'lead', 'display' => 2],
                'fieldparams' => ['multiple' => 0],
            ],
            ['context' => 'com_content.article', 'id' => 7, 'params' => ['showlabel' => 0]]
        );

        // The keys the caller did not mention must survive: Joomla replaces the whole params
        // column with whatever it is sent, so a partial object would discard them.
        $this->assertSame(
            ['showlabel' => 0, 'render_class' => 'lead', 'display' => 2],
            $captured['body']['params']
        );
        $this->assertSame(['multiple' => 0], $captured['body']['fieldparams']);
        $this->assertSame('api/index.php/v1/fields/content/articles/7', $captured['path']);
    }

    public function testUpdateFieldResendsExistingCategoryAssignmentsWhenOmitted(): void
    {
        $captured = $this->captureFieldPatch(
            ['assigned_cat_ids' => [14, 19]],
            ['context' => 'com_content.article', 'id' => 7, 'title' => 'Region']
        );

        // assigned_cat_ids is not a column, so Joomla's PATCH backfill never restores it and
        // FieldModel::save() deletes every assignment when it is absent.
        $this->assertSame([14, 19], $captured['body']['assigned_cat_ids']);
    }

    public function testUpdateFieldPreservesTheAllCategoriesSentinel(): void
    {
        $captured = $this->captureFieldPatch(
            ['assigned_cat_ids' => [0]],
            ['context' => 'com_content.article', 'id' => 7, 'title' => 'Region']
        );

        $this->assertSame([0], $captured['body']['assigned_cat_ids']);
    }

    public function testUpdateFieldNeverSendsPermissionRules(): void
    {
        $captured = $this->captureFieldPatch(
            ['params' => [], 'rules' => ['core.edit' => ['2' => 1]]],
            ['context' => 'com_content.article', 'id' => 7, 'title' => 'Region']
        );

        // FieldTable::bind only calls setRules() when the key is present, so omitting it is what
        // leaves the field's permissions untouched.
        $this->assertArrayNotHasKey('rules', $captured['body']);
    }

    public function testUpdateFieldRejectsACallThatChangesNothing(): void
    {
        $service = $this->makeService($this->createRestMock());
        $response = $this->callTool($service, 'update_field', ['context' => 'com_content.article', 'id' => 7]);

        $this->assertTrue($service->wasLastCallFailed());
        $this->assertStringContainsString('No updatable fields supplied', $this->errorText($response));
    }

    /**
     * @dataProvider fieldContextRoutes
     */
    public function testEveryCoreContextResolvesToItsRoute(string $context, string $fieldsPath, string $groupsPath): void
    {
        $paths = [];
        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(
            static function (string $path) use (&$paths): array {
                $paths[] = $path;

                return ['data' => []];
            }
        );

        $service = $this->makeService($rest);
        $this->callTool($service, 'list_fields', ['context' => $context]);
        $this->callTool($service, 'list_field_groups', ['context' => $context]);

        $this->assertSame([$fieldsPath, $groupsPath], $paths);
    }

    /**
     * @return array<string, array{0:string, 1:string, 2:string}>
     */
    public static function fieldContextRoutes(): array
    {
        return [
            'articles' => [
                'com_content.article',
                'api/index.php/v1/fields/content/articles',
                'api/index.php/v1/fields/groups/content/articles',
            ],
            'article categories' => [
                'com_content.categories',
                'api/index.php/v1/fields/content/categories',
                'api/index.php/v1/fields/groups/content/categories',
            ],
            'contacts' => [
                'com_contact.contact',
                'api/index.php/v1/fields/contacts/contact',
                'api/index.php/v1/fields/groups/contacts/contact',
            ],
            'contact mail' => [
                'com_contact.mail',
                'api/index.php/v1/fields/contacts/mail',
                'api/index.php/v1/fields/groups/contacts/mail',
            ],
            'contact categories' => [
                'com_contact.categories',
                'api/index.php/v1/fields/contacts/categories',
                'api/index.php/v1/fields/groups/contacts/categories',
            ],
            'users' => [
                'com_users.user',
                'api/index.php/v1/fields/users',
                'api/index.php/v1/fields/groups/users',
            ],
        ];
    }

    public function testUnknownContextIsRejectedAndNamesTheValidOnes(): void
    {
        $service = $this->makeService($this->createRestMock());
        $response = $this->callTool($service, 'list_fields', ['context' => 'com_dpcalendar.event']);
        $message = $this->errorText($response);

        $this->assertTrue($service->wasLastCallFailed());
        $this->assertStringContainsString('com_dpcalendar.event', $message);

        foreach (self::fieldContextRoutes() as [$validContext]) {
            $this->assertStringContainsString($validContext, $message);
        }
    }

    public function testGetFieldSplitsStoredValueFromLabelWithoutTouchingFieldparams(): void
    {
        $fieldparams = [
            'multiple' => 0,
            'options' => [
                'option0' => ['name' => 'Europe & Middle East', 'value' => 'emea'],
                'option1' => ['name' => 'Americas', 'value' => 'amer'],
            ],
        ];

        $rest = $this->createRestMock();
        $rest->method('get')->willReturn($this->fieldResponse(['type' => 'list', 'fieldparams' => $fieldparams]));

        $service = $this->makeService($rest);
        $attributes = $this->toolResult($this->callTool($service, 'get_field', [
            'context' => 'com_content.article',
            'id' => 7,
        ]))['data']['attributes'];

        $this->assertSame(
            [
                ['value' => 'emea', 'label' => 'Europe & Middle East'],
                ['value' => 'amer', 'label' => 'Americas'],
            ],
            $attributes['options']
        );
        $this->assertSame($fieldparams, $attributes['fieldparams']);
    }

    public function testGetItemFieldValuesReportsRawValueAndLabelSeparately(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(
            fn (string $path): array => str_contains($path, '/fields/')
                ? ['data' => [$this->fieldRecord([
                    'id' => 7,
                    'name' => 'region',
                    'label' => 'Region',
                    'type' => 'list',
                    'fieldparams' => ['options' => ['option0' => ['name' => 'Europe & Middle East', 'value' => 'emea']]],
                ])]]
                : ['data' => ['id' => '12', 'attributes' => ['id' => 12, 'title' => 'Hello', 'region' => 'emea']]]
        );

        $service = $this->makeService($rest);
        $result = $this->toolResult($this->callTool($service, 'get_item_field_values', [
            'context' => 'com_content.article',
            'item_id' => 12,
        ]));

        $this->assertSame([[
            'field_id' => 7,
            'name' => 'region',
            'label' => 'Region',
            'type' => 'list',
            'raw_value' => 'emea',
            'display_value' => 'Europe & Middle East',
        ]], $result['values']);
    }

    public function testGetItemFieldValuesFallsBackToTheStoredValueWhenAnOptionWasRemoved(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(
            fn (string $path): array => str_contains($path, '/fields/')
                ? ['data' => [$this->fieldRecord([
                    'id' => 7,
                    'name' => 'region',
                    'type' => 'list',
                    'fieldparams' => ['options' => ['option0' => ['name' => 'Americas', 'value' => 'amer']]],
                ])]]
                : ['data' => ['attributes' => ['region' => 'emea']]]
        );

        $service = $this->makeService($rest);
        $result = $this->toolResult($this->callTool($service, 'get_item_field_values', [
            'context' => 'com_content.article',
            'item_id' => 12,
        ]));

        $this->assertSame('emea', $result['values'][0]['raw_value']);
        $this->assertSame('emea', $result['values'][0]['display_value']);
    }

    public function testSetItemFieldValuesRejectsAnUnknownFieldNameAndListsTheValidOnes(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn(['data' => [$this->fieldRecord(['name' => 'region'])]]);
        $rest->expects($this->never())->method('patch');

        $service = $this->makeService($rest);
        $response = $this->callTool($service, 'set_item_field_values', [
            'context' => 'com_content.article',
            'item_id' => 12,
            'values' => ['regoin' => 'emea'],
        ]);
        $message = $this->errorText($response);

        $this->assertTrue($service->wasLastCallFailed());
        $this->assertStringContainsString('regoin', $message);
        $this->assertStringContainsString('region', $message);
    }

    public function testSetItemFieldValuesRejectsContextsJoomlaCannotWrite(): void
    {
        foreach (['com_content.categories', 'com_contact.mail'] as $context) {
            $rest = $this->createRestMock();
            $rest->expects($this->never())->method('patch');

            $service = $this->makeService($rest);
            $response = $this->callTool($service, 'set_item_field_values', [
                'context' => $context,
                'item_id' => 12,
                'values' => ['region' => 'emea'],
            ]);

            $this->assertTrue($service->wasLastCallFailed(), $context . ' should be rejected');
            $this->assertStringContainsString($context, $this->errorText($response));
        }
    }

    public function testFindFieldByNameMatchesTheTechnicalNameCaseInsensitively(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn(['data' => [
            $this->fieldRecord(['id' => 3, 'name' => 'department']),
            $this->fieldRecord(['id' => 7, 'name' => 'region']),
        ]]);

        $service = $this->makeService($rest);
        $result = $this->toolResult($this->callTool($service, 'find_field_by_name', [
            'context' => 'com_content.article',
            'name' => 'REGION',
        ]));

        $this->assertSame(7, $result['data']['attributes']['id']);
        $this->assertSame([], $result['data']['attributes']['options']);
    }

    public function testFindFieldByNameReportsAClearMissWithoutGuessingAnId(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn(['data' => [$this->fieldRecord(['name' => 'region'])]]);

        $service = $this->makeService($rest);
        $response = $this->callTool($service, 'find_field_by_name', [
            'context' => 'com_content.article',
            'name' => 'nope',
        ]);

        $this->assertTrue($service->wasLastCallFailed());
        $this->assertStringContainsString('nope', $this->errorText($response));
    }

    public function testReorderFieldsValidatesEveryIdBeforeWritingAnything(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn(['data' => [
            $this->fieldRecord(['id' => 3, 'name' => 'a']),
            $this->fieldRecord(['id' => 7, 'name' => 'b']),
        ]]);
        $rest->expects($this->never())->method('patch');

        $service = $this->makeService($rest);
        $response = $this->callTool($service, 'reorder_fields', [
            'context' => 'com_content.article',
            'ordered_ids' => [7, 999],
        ]);

        $this->assertTrue($service->wasLastCallFailed());
        $this->assertStringContainsString('999', $this->errorText($response));
    }

    public function testReorderFieldsAssignsSequentialOrderingAndKeepsCategoryAssignments(): void
    {
        $patches = [];
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn(['data' => [
            $this->fieldRecord(['id' => 3, 'name' => 'a', 'assigned_cat_ids' => [14]]),
            $this->fieldRecord(['id' => 7, 'name' => 'b', 'assigned_cat_ids' => [0]]),
        ]]);
        $rest->method('patch')->willReturnCallback(
            static function (string $path, array $body) use (&$patches): array {
                $patches[] = ['path' => $path, 'body' => $body];

                return [];
            }
        );

        $service = $this->makeService($rest);
        $this->callTool($service, 'reorder_fields', [
            'context' => 'com_content.article',
            'ordered_ids' => [7, 3],
        ]);

        $this->assertSame('api/index.php/v1/fields/content/articles/7', $patches[0]['path']);
        $this->assertSame(1, $patches[0]['body']['ordering']);
        $this->assertSame([0], $patches[0]['body']['assigned_cat_ids']);
        $this->assertSame(2, $patches[1]['body']['ordering']);
        $this->assertSame([14], $patches[1]['body']['assigned_cat_ids']);
    }

    public function testReorderFieldsRejectsADuplicateId(): void
    {
        $service = $this->makeService($this->createRestMock());
        $response = $this->callTool($service, 'reorder_fields', [
            'context' => 'com_content.article',
            'ordered_ids' => [7, 7],
        ]);

        $this->assertTrue($service->wasLastCallFailed());
        $this->assertStringContainsString('more than once', $this->errorText($response));
    }

    public function testDeleteFieldTrashesBeforeDeleting(): void
    {
        $calls = [];
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn($this->fieldResponse(['state' => 1]));
        $rest->method('patch')->willReturnCallback(
            static function (string $path, array $body) use (&$calls): array {
                $calls[] = ['patch', $body];

                return [];
            }
        );
        $rest->method('delete')->willReturnCallback(
            static function (string $path) use (&$calls): array {
                $calls[] = ['delete', []];

                return ['meta' => ['deleted' => true]];
            }
        );

        $service = $this->makeService($rest);
        $this->callTool($service, 'delete_field', ['context' => 'com_content.article', 'id' => 7]);

        $this->assertSame('patch', $calls[0][0]);
        $this->assertSame(-2, $calls[0][1]['state']);
        $this->assertSame('delete', $calls[1][0]);
    }

    public function testDeleteFieldSkipsTheTrashStepWhenAlreadyTrashed(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn($this->fieldResponse(['state' => -2]));
        $rest->expects($this->never())->method('patch');
        $rest->method('delete')->willReturn(['meta' => ['deleted' => true]]);

        $service = $this->makeService($rest);
        $this->callTool($service, 'delete_field', ['context' => 'com_content.article', 'id' => 7]);

        $this->assertFalse($service->wasLastCallFailed());
    }

    public function testCreateFieldPostsContextAndDefaults(): void
    {
        $captured = null;
        $rest = $this->createRestMock();
        $rest->method('post')->willReturnCallback(
            static function (string $path, array $body) use (&$captured): array {
                $captured = ['path' => $path, 'body' => $body];

                return ['data' => ['id' => '7']];
            }
        );

        $service = $this->makeService($rest);
        $this->callTool($service, 'create_field', [
            'context' => 'com_content.article',
            'title' => 'Region',
            'type' => 'list',
        ]);

        $this->assertSame('api/index.php/v1/fields/content/articles', $captured['path']);
        $this->assertSame('com_content.article', $captured['body']['context']);
        $this->assertSame('list', $captured['body']['type']);
        $this->assertSame(1, $captured['body']['state']);
        $this->assertSame('*', $captured['body']['language']);
        // Omitted on create so Joomla applies its own "all categories" default.
        $this->assertArrayNotHasKey('assigned_cat_ids', $captured['body']);
    }

    public function testListFieldsMapsFiltersOntoJoomlaQueryParameters(): void
    {
        $captured = null;
        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(
            static function (string $path, array $query) use (&$captured): array {
                $captured = $query;

                return ['data' => []];
            }
        );

        $service = $this->makeService($rest);
        $this->callTool($service, 'list_fields', [
            'context' => 'com_content.article',
            'group_id' => 4,
            'state' => 1,
            'search' => 'reg',
            'limit' => 10,
            'offset' => 20,
        ]);

        $this->assertSame(4, $captured['filter[group_id]']);
        $this->assertSame(1, $captured['filter[state]']);
        $this->assertSame('reg', $captured['filter[search]']);
        $this->assertSame(10, $captured['page[limit]']);
        $this->assertSame(20, $captured['page[offset]']);
        $this->assertArrayNotHasKey('group_id', $captured);
    }

    public function testAMissingFieldsRouteNamesThePluginToEnable(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willThrowException($this->notFound());

        $service = $this->makeService($rest);
        $response = $this->callTool($service, 'list_fields', ['context' => 'com_contact.contact']);

        $this->assertTrue($service->wasLastCallFailed());
        $this->assertStringContainsString('plg_webservices_contact', $this->errorText($response));
    }

    public function testAMissingFieldReportsTheIdRatherThanThePlugin(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willThrowException($this->notFound());

        $service = $this->makeService($rest);
        $response = $this->callTool($service, 'get_field', ['context' => 'com_content.article', 'id' => 404]);
        $message = $this->errorText($response);

        $this->assertTrue($service->wasLastCallFailed());
        $this->assertStringContainsString('404', $message);
        $this->assertStringNotContainsString('plg_webservices', $message);
    }

    public function testUpdateFieldGroupMergesParamsAndNeverSendsCategoryAssignments(): void
    {
        $captured = null;
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn(['data' => ['type' => 'groups', 'id' => '4', 'attributes' => [
            'id' => 4,
            'title' => 'Tab',
            'params' => ['display' => 2, 'label_render_class' => 'bold'],
        ]]]);
        $rest->method('patch')->willReturnCallback(
            static function (string $path, array $body) use (&$captured): array {
                $captured = ['path' => $path, 'body' => $body];

                return [];
            }
        );

        $service = $this->makeService($rest);
        $this->callTool($service, 'update_field_group', [
            'context' => 'com_content.article',
            'id' => 4,
            'params' => ['display' => 0],
        ]);

        $this->assertSame(['display' => 0, 'label_render_class' => 'bold'], $captured['body']['params']);
        $this->assertSame('api/index.php/v1/fields/groups/content/articles/4', $captured['path']);
        // Field groups have no category assignments; only fields do.
        $this->assertArrayNotHasKey('assigned_cat_ids', $captured['body']);
    }

    public function testCreateFieldGroupPostsToTheGroupsCollection(): void
    {
        $captured = null;
        $rest = $this->createRestMock();
        $rest->method('post')->willReturnCallback(
            static function (string $path, array $body) use (&$captured): array {
                $captured = ['path' => $path, 'body' => $body];

                return ['data' => ['id' => '4']];
            }
        );

        $service = $this->makeService($rest);
        $this->callTool($service, 'create_field_group', ['context' => 'com_contact.contact', 'title' => 'Extra']);

        $this->assertSame('api/index.php/v1/fields/groups/contacts/contact', $captured['path']);
        $this->assertSame('com_contact.contact', $captured['body']['context']);
        $this->assertSame(1, $captured['body']['state']);
    }

    public function testDeleteFieldGroupTrashesBeforeDeleting(): void
    {
        $verbs = [];
        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(
            static function () use (&$verbs): array {
                $verbs[] = 'get';

                return ['data' => ['attributes' => ['id' => 4, 'state' => 1]]];
            }
        );
        $rest->method('patch')->willReturnCallback(
            static function () use (&$verbs): array {
                $verbs[] = 'patch';

                return [];
            }
        );
        $rest->method('delete')->willReturnCallback(
            static function () use (&$verbs): array {
                $verbs[] = 'delete';

                return [];
            }
        );

        $service = $this->makeService($rest);
        $this->callTool($service, 'delete_field_group', ['context' => 'com_content.article', 'id' => 4]);

        $this->assertSame(['get', 'patch', 'delete'], $verbs);
    }

    /**
     * @dataProvider fieldValueItemRoutes
     */
    public function testItemFieldValuesUseTheOwningItemRoute(string $context, string $itemPath): void
    {
        $paths = [];
        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(
            static function (string $path) use (&$paths): array {
                $paths[] = $path;

                return str_contains($path, '/fields/') ? ['data' => []] : ['data' => ['attributes' => []]];
            }
        );

        $service = $this->makeService($rest);
        $this->callTool($service, 'get_item_field_values', ['context' => $context, 'item_id' => 12]);

        $this->assertContains($itemPath, $paths);
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function fieldValueItemRoutes(): array
    {
        return [
            'articles' => ['com_content.article', 'api/index.php/v1/content/articles/12'],
            'categories' => ['com_content.categories', 'api/index.php/v1/content/categories/12'],
            'contacts' => ['com_contact.contact', 'api/index.php/v1/contacts/12'],
            'users' => ['com_users.user', 'api/index.php/v1/users/12'],
        ];
    }

    public function testContactMailHasNoItemsSoItsValuesCannotBeRead(): void
    {
        $service = $this->makeService($this->createRestMock());
        $response = $this->callTool($service, 'get_item_field_values', [
            'context' => 'com_contact.mail',
            'item_id' => 12,
        ]);

        $this->assertTrue($service->wasLastCallFailed());
        $this->assertStringContainsString('com_contact.mail', $this->errorText($response));
    }

    public function testSetItemFieldValuesPatchesTheItemKeyedByTechnicalName(): void
    {
        $captured = null;
        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(
            fn (string $path): array => str_contains($path, '/fields/')
                ? ['data' => [$this->fieldRecord(['name' => 'region'])]]
                : ['data' => ['attributes' => ['region' => 'emea']]]
        );
        $rest->method('patch')->willReturnCallback(
            static function (string $path, array $body) use (&$captured): array {
                $captured = ['path' => $path, 'body' => $body];

                return [];
            }
        );

        $service = $this->makeService($rest);
        $result = $this->toolResult($this->callTool($service, 'set_item_field_values', [
            'context' => 'com_users.user',
            'item_id' => 12,
            'values' => ['region' => 'emea'],
        ]));

        $this->assertSame('api/index.php/v1/users/12', $captured['path']);
        $this->assertSame(['region' => 'emea'], $captured['body']);
        $this->assertSame('emea', $result['values'][0]['raw_value']);
    }

    public function testMultiValueFieldsResolveEachElementToItsLabel(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(
            fn (string $path): array => str_contains($path, '/fields/')
                ? ['data' => [$this->fieldRecord([
                    'name' => 'tags',
                    'type' => 'checkboxes',
                    'fieldparams' => ['options' => [
                        'option0' => ['name' => 'Alpha', 'value' => 'a'],
                        'option1' => ['name' => 'Beta', 'value' => 'b'],
                    ]],
                ])]]
                : ['data' => ['attributes' => ['tags' => ['a', 'b']]]]
        );

        $service = $this->makeService($rest);
        $result = $this->toolResult($this->callTool($service, 'get_item_field_values', [
            'context' => 'com_content.article',
            'item_id' => 12,
        ]));

        $this->assertSame(['a', 'b'], $result['values'][0]['raw_value']);
        $this->assertSame(['Alpha', 'Beta'], $result['values'][0]['display_value']);
    }

    public function testReadOnlyModeBlocksFieldWritesButNotFieldReads(): void
    {
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn(['data' => []]);

        $service = $this->makeService($rest, true);

        $this->callTool($service, 'create_field', ['context' => 'com_content.article', 'title' => 'X']);
        $this->assertTrue($service->wasLastCallBlocked() || $service->wasLastCallFailed());

        $this->callTool($service, 'list_fields', ['context' => 'com_content.article']);
        $this->assertFalse($service->wasLastCallFailed());
    }

    public function testSetItemFieldValuesInvalidatesTheCachedArticle(): void
    {
        $cache = new CacheService(new SimpleArrayCache());
        $cache->remember('article:12', static fn (): array => ['stale']);
        $cache->remember('articles_search:abc', static fn (): array => ['stale']);
        $cache->remember('article_version:5', static fn (): array => ['kept']);

        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(
            fn (string $path): array => str_contains($path, '/fields/')
                ? ['data' => [$this->fieldRecord(['name' => 'region'])]]
                : ['data' => ['attributes' => ['region' => 'apac']]]
        );

        $service = $this->makeService($rest, false, $cache);
        $this->callTool($service, 'set_item_field_values', [
            'context' => 'com_content.article',
            'item_id' => 12,
            'values' => ['region' => 'apac'],
        ]);

        // Field values are embedded in the article's own API representation, so a cached article
        // read after this write would otherwise serve the pre-update value.
        $this->assertSame('miss', $cache->remember('article:12', static fn (): string => 'miss'));
        $this->assertSame('miss', $cache->remember('articles_search:abc', static fn (): string => 'miss'));
        // The prefix delete must not reach neighbouring keys.
        $this->assertSame(['kept'], $cache->remember('article_version:5', static fn (): string => 'miss'));
    }

    /**
     * @dataProvider articleFieldDefinitionWrites
     *
     * @param  array<string, mixed>  $arguments
     */
    public function testFieldDefinitionWritesInvalidateCachedArticles(string $tool, array $arguments): void
    {
        $cache = new CacheService(new SimpleArrayCache());
        $cache->remember('article:12', static fn (): array => ['stale']);

        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(
            fn (string $path): array => preg_match('~/\d+$~', $path) === 1
                ? $this->fieldResponse(['state' => 1])
                : ['data' => [$this->fieldRecord(['id' => 7, 'name' => 'region'])]]
        );
        $rest->method('post')->willReturn(['data' => ['id' => '7']]);

        $service = $this->makeService($rest, false, $cache);
        $this->callTool($service, $tool, $arguments);

        $this->assertFalse($service->wasLastCallFailed(), $tool . ' failed: nothing was invalidated');
        $this->assertSame(
            'miss',
            $cache->remember('article:12', static fn (): string => 'miss'),
            $tool . ' left a stale article in the cache'
        );
    }

    /**
     * @return array<string, array{0:string, 1:array<string, mixed>}>
     */
    public static function articleFieldDefinitionWrites(): array
    {
        return [
            'create_field' => ['create_field', ['context' => 'com_content.article', 'title' => 'Region']],
            'update_field' => ['update_field', ['context' => 'com_content.article', 'id' => 7, 'title' => 'Region']],
            'delete_field' => ['delete_field', ['context' => 'com_content.article', 'id' => 7]],
            'reorder_fields' => ['reorder_fields', ['context' => 'com_content.article', 'ordered_ids' => [7]]],
        ];
    }

    public function testWritesInOtherContextsLeaveTheArticleCacheAlone(): void
    {
        $cache = new CacheService(new SimpleArrayCache());
        $cache->remember('article:12', static fn (): array => ['kept']);

        $rest = $this->createRestMock();
        $rest->method('get')->willReturnCallback(
            fn (string $path): array => str_contains($path, '/fields/')
                ? ['data' => [$this->fieldRecord(['name' => 'region'])]]
                : ['data' => ['attributes' => ['region' => 'apac']]]
        );

        $service = $this->makeService($rest, false, $cache);
        $this->callTool($service, 'set_item_field_values', [
            'context' => 'com_users.user',
            'item_id' => 3,
            'values' => ['region' => 'apac'],
        ]);

        $this->assertSame(['kept'], $cache->remember('article:12', static fn (): string => 'miss'));
    }

    // --- helpers ---------------------------------------------------------

    /**
     * Run update_field against a stubbed existing record and return the PATCH that resulted.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $arguments
     * @return array{path:string, body:array<string, mixed>}
     */
    private function captureFieldPatch(array $existing, array $arguments): array
    {
        $captured = null;
        $rest = $this->createRestMock();
        $rest->method('get')->willReturn($this->fieldResponse($existing));
        $rest->method('patch')->willReturnCallback(
            static function (string $path, array $body) use (&$captured): array {
                $captured = ['path' => $path, 'body' => $body];

                return [];
            }
        );

        $service = $this->makeService($rest);
        $this->callTool($service, 'update_field', $arguments);

        $this->assertIsArray($captured, 'update_field did not issue a PATCH');

        return $captured;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function fieldResponse(array $attributes): array
    {
        return ['data' => $this->fieldRecord($attributes)];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function fieldRecord(array $attributes): array
    {
        $attributes += ['id' => 7, 'name' => 'region', 'title' => 'Region', 'type' => 'text'];

        return ['type' => 'fields', 'id' => (string) $attributes['id'], 'attributes' => $attributes];
    }

    private function notFound(): \GuzzleHttp\Exception\RequestException
    {
        return new \GuzzleHttp\Exception\RequestException(
            'Not Found',
            new \GuzzleHttp\Psr7\Request('GET', 'https://example.test'),
            new \GuzzleHttp\Psr7\Response(404, [], '{"errors":[{"code":404}]}')
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function errorText(array $response): string
    {
        return (string) ($response['result']['content'][0]['text'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function toolResult(array $response): array
    {
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('structuredContent', $response['result']);

        return $response['result']['structuredContent'];
    }

    /**
     * @return RestClient&MockObject
     */
    private function createRestMock(): RestClient
    {
        return $this->createMock(RestClient::class);
    }

    private function makeService(RestClient $rest, bool $readOnly = false, ?CacheService $cache = null): RpcService
    {
        $policy = $this->createMock(PolicyService::class);
        $policy->method('isToolAllowed')->willReturn(true);
        $policy->method('isReadOnly')->willReturn($readOnly);

        return new RpcService(
            $rest,
            $cache ?? new CacheService(new SimpleArrayCache()),
            $policy,
            $this->createMock(LoggerInterface::class),
            new ToolRegistry(),
            new SchemaValidator(),
            new PromptRegistry()
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(RpcService $service, string $name, array $arguments): array
    {
        return $service->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);
    }
}
