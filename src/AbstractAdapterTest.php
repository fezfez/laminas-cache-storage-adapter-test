<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter;

use ArrayObject;
use Laminas\Cache;
use Laminas\Cache\Exception;
use Laminas\Cache\Exception\InvalidArgumentException;
use Laminas\Cache\Exception\RuntimeException;
use Laminas\Cache\Storage\Adapter\AbstractAdapter;
use Laminas\Cache\Storage\Adapter\AdapterOptions;
use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\Event;
use Laminas\Cache\Storage\Plugin\PluginOptions;
use Laminas\Cache\Storage\PostEvent;
use Laminas\EventManager\ResponseCollection;
use LaminasTest\Cache\Storage\TestAsset\MockPlugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

use function array_keys;
use function array_map;
use function array_unique;
use function call_user_func_array;
use function count;
use function current;
use function get_class;
use function ucfirst;

/**
 * @group      Laminas_Cache
 * @covers Laminas\Cache\Storage\Adapter\AdapterOptions<extended>
 */
class AbstractAdapterTest extends TestCase
{
    /** @var AbstractAdapter */
    protected $storage;

    /** @var AdapterOptions */
    protected $options;

    public function setUp(): void
    {
        $this->options = new AdapterOptions();
    }

    public function testGetOptions(): void
    {
        $this->storage = $this->getMockForAbstractAdapter();

        $options = $this->storage->getOptions();
        $this->assertInstanceOf(AdapterOptions::class, $options);
        $this->assertIsBool($options->getWritable());
        $this->assertIsBool($options->getReadable());
        $this->assertIsInt($options->getTtl());
        $this->assertIsString($options->getNamespace());
        $this->assertIsString($options->getKeyPattern());
    }

    public function testSetWritable(): void
    {
        $this->options->setWritable(true);
        $this->assertTrue($this->options->getWritable());

        $this->options->setWritable(false);
        $this->assertFalse($this->options->getWritable());
    }

    public function testSetReadable(): void
    {
        $this->options->setReadable(true);
        $this->assertTrue($this->options->getReadable());

        $this->options->setReadable(false);
        $this->assertFalse($this->options->getReadable());
    }

    public function testSetTtl(): void
    {
        $this->options->setTtl('123');
        $this->assertSame(123, $this->options->getTtl());
    }

    public function testSetTtlThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setTtl(-1);
    }

    public function testGetDefaultNamespaceNotEmpty(): void
    {
        $ns = $this->options->getNamespace();
        $this->assertNotEmpty($ns);
    }

    public function testSetNamespace(): void
    {
        $this->options->setNamespace('new_namespace');
        $this->assertSame('new_namespace', $this->options->getNamespace());
    }

    public function testSetNamespace0(): void
    {
        $this->options->setNamespace('0');
        $this->assertSame('0', $this->options->getNamespace());
    }

    public function testSetKeyPattern(): void
    {
        $this->options->setKeyPattern('/^[key]+$/Di');
        $this->assertEquals('/^[key]+$/Di', $this->options->getKeyPattern());
    }

    public function testUnsetKeyPattern(): void
    {
        $this->options->setKeyPattern(null);
        $this->assertSame('', $this->options->getKeyPattern());
    }

    public function testSetKeyPatternThrowsExceptionOnInvalidPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->options->setKeyPattern('#');
    }

    public function testPluginRegistry(): void
    {
        $this->markTestIncomplete();

        $this->storage = $this->getMockForAbstractAdapter();

        $plugin = new MockPlugin();

        // no plugin registered
        $this->assertFalse($this->storage->hasPlugin($plugin));
        $this->assertEquals(0, count($this->storage->getPluginRegistry()));
        $this->assertEquals(0, count($plugin->getHandles()));

        // register a plugin
        $this->assertSame($this->storage, $this->storage->addPlugin($plugin));
        $this->assertTrue($this->storage->hasPlugin($plugin));
        $this->assertEquals(1, count($this->storage->getPluginRegistry()));

        // test registered callback handles
        $handles = $plugin->getHandles();
        $this->assertCount(2, $handles);

        // test unregister a plugin
        $this->assertSame($this->storage, $this->storage->removePlugin($plugin));
        $this->assertFalse($this->storage->hasPlugin($plugin));
        $this->assertEquals(0, count($this->storage->getPluginRegistry()));
        $this->assertEquals(0, count($plugin->getHandles()));
    }

    public function testInternalTriggerPre(): void
    {
        $this->markTestIncomplete();

        $this->storage = $this->getMockForAbstractAdapter();

        $plugin = new MockPlugin();
        $this->storage->addPlugin($plugin);

        $params = new ArrayObject([
            'key'   => 'key1',
            'value' => 'value1',
        ]);

        // call protected method
        $method = new ReflectionMethod(get_class($this->storage), 'triggerPre');
        $method->setAccessible(true);
        $rsCollection = $method->invoke($this->storage, 'setItem', $params);
        $this->assertInstanceOf(ResponseCollection::class, $rsCollection);

        // test called event
        $calledEvents = $plugin->getCalledEvents();
        $this->assertEquals(1, count($calledEvents));

        $event = current($calledEvents);
        $this->assertInstanceOf(Event::class, $event);
        $this->assertEquals('setItem.pre', $event->getName());
        $this->assertSame($this->storage, $event->getTarget());
        $this->assertSame($params, $event->getParams());
    }

    public function testInternalTriggerPost(): void
    {
        $this->markTestIncomplete();

        $this->storage = $this->getMockForAbstractAdapter();

        $plugin = new MockPlugin();
        $this->storage->addPlugin($plugin);

        $params = new ArrayObject([
            'key'   => 'key1',
            'value' => 'value1',
        ]);
        $result = true;

        // call protected method
        $method = new ReflectionMethod(get_class($this->storage), 'triggerPost');
        $method->setAccessible(true);
        $result = $method->invokeArgs($this->storage, ['setItem', $params, &$result]);

        // test called event
        $calledEvents = $plugin->getCalledEvents();
        $this->assertEquals(1, count($calledEvents));
        $event = current($calledEvents);

        // return value of triggerPost and the called event should be the same
        $this->assertSame($result, $event->getResult());

        $this->assertInstanceOf(PostEvent::class, $event);
        $this->assertEquals('setItem.post', $event->getName());
        $this->assertSame($this->storage, $event->getTarget());
        $this->assertSame($params, $event->getParams());
        $this->assertSame($result, $event->getResult());
    }

    public function testInternalTriggerExceptionThrowRuntimeException(): void
    {
        $this->markTestIncomplete();

        $this->storage = $this->getMockForAbstractAdapter();

        $plugin = new MockPlugin();
        $this->storage->addPlugin($plugin);

        $result = null;
        $params = new ArrayObject([
            'key'   => 'key1',
            'value' => 'value1',
        ]);

        // call protected method
        $method = new ReflectionMethod(get_class($this->storage), 'triggerException');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test');
        $method->invokeArgs($this->storage, ['setItem', $params, &$result, new Exception\RuntimeException('test')]);
    }

    public function testGetItemCallsInternalGetItem(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalGetItem']);

        $key    = 'key1';
        $result = 'value1';

        $this->storage
            ->expects($this->once())
            ->method('internalGetItem')
            ->with($this->equalTo($key))
            ->will($this->returnValue($result));

        $rs = $this->storage->getItem($key);
        $this->assertEquals($result, $rs);
    }

    public function testGetItemsCallsInternalGetItems(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalGetItems']);

        $keys   = ['key1', 'key2'];
        $result = ['key2' => 'value2'];

        $this->storage
            ->expects($this->once())
            ->method('internalGetItems')
            ->with($this->equalTo($keys))
            ->will($this->returnValue($result));

        $rs = $this->storage->getItems($keys);
        $this->assertEquals($result, $rs);
    }

    public function testInternalGetItemsCallsInternalGetItemForEachKey(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalGetItem']);

        $items  = ['key1' => 'value1', 'keyNotFound' => false, 'key2' => 'value2'];
        $result = ['key1' => 'value1', 'key2' => 'value2'];

        foreach ($items as $key => $value) {
            $this->storage->expects($this->any())
                ->method('internalGetItem')
                ->with(
                    $this->stringContains('key'),
                    $this->anything()
                )
                ->will($this->returnCallback(function ($key, &$success) use ($items) {
                    if ($items[$key]) {
                        $success = true;
                        return $items[$key];
                    } else {
                        $success = false;
                        return null;
                    }
                }));
        }

        $this->assertSame($result, $this->storage->getItems(array_keys($items)));
    }

    public function testHasItemCallsInternalHasItem(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalHasItem']);

        $key    = 'key1';
        $result = true;

        $this->storage
            ->expects($this->once())
            ->method('internalHasItem')
            ->with($this->equalTo($key))
            ->will($this->returnValue($result));

        $rs = $this->storage->hasItem($key);
        $this->assertSame($result, $rs);
    }

    public function testHasItemsCallsInternalHasItems(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalHasItems']);

        $keys   = ['key1', 'key2'];
        $result = ['key2'];

        $this->storage
            ->expects($this->once())
            ->method('internalHasItems')
            ->with($this->equalTo($keys))
            ->will($this->returnValue($result));

        $rs = $this->storage->hasItems($keys);
        $this->assertEquals($result, $rs);
    }

    public function testInternalHasItemsCallsInternalHasItem(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalHasItem']);

        $items = ['key1' => true];

        $this->storage
            ->expects($this->atLeastOnce())
            ->method('internalHasItem')
            ->with($this->equalTo('key1'))
            ->will($this->returnValue(true));

        $rs = $this->storage->hasItems(array_keys($items));
        $this->assertEquals(['key1'], $rs);
    }

    public function testGetItemReturnsNullIfFailed(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalGetItem']);

        $key = 'key1';

        // Do not throw exceptions outside the adapter
        $pluginOptions = new PluginOptions(
            ['throw_exceptions' => false]
        );
        $plugin        = new Cache\Storage\Plugin\ExceptionHandler();
        $plugin->setOptions($pluginOptions);
        $this->storage->addPlugin($plugin);

        // Simulate internalGetItem() throwing an exception
        $this->storage
            ->expects($this->once())
            ->method('internalGetItem')
            ->with($this->equalTo($key))
            ->will($this->throwException(new \Exception('internalGetItem failed')));

        $result = $this->storage->getItem($key, $success);
        $this->assertNull($result, 'GetItem should return null the item cannot be retrieved');
        $this->assertFalse($success, '$success should be false if the item cannot be retrieved');
    }

    public function simpleEventHandlingMethodDefinitions(): array
    {
        $capabilities = new Capabilities($this->getMockForAbstractAdapter(), new stdClass());

        return [
            //    name, internalName, args, returnValue
            ['hasItem', 'internalGetItem', ['k'], 'v'],
            ['hasItems', 'internalHasItems', [['k1', 'k2']], ['v1', 'v2']],
            ['getItem', 'internalGetItem', ['k'], 'v'],
            ['getItems', 'internalGetItems', [['k1', 'k2']], ['k1' => 'v1', 'k2' => 'v2']],
            ['getMetadata', 'internalGetMetadata', ['k'], []],
            ['getMetadatas', 'internalGetMetadatas', [['k1', 'k2']], ['k1' => [], 'k2' => []]],
            ['setItem', 'internalSetItem', ['k', 'v'], true],
            ['setItems', 'internalSetItems', [['k1' => 'v1', 'k2' => 'v2']], []],
            ['replaceItem', 'internalReplaceItem', ['k', 'v'], true],
            ['replaceItems', 'internalReplaceItems', [['k1' => 'v1', 'k2' => 'v2']], []],
            ['addItem', 'internalAddItem', ['k', 'v'], true],
            ['addItems', 'internalAddItems', [['k1' => 'v1', 'k2' => 'v2']], []],
            ['checkAndSetItem', 'internalCheckAndSetItem', [123, 'k', 'v'], true],
            ['touchItem', 'internalTouchItem', ['k'], true],
            ['touchItems', 'internalTouchItems', [['k1', 'k2']], []],
            ['removeItem', 'internalRemoveItem', ['k'], true],
            ['removeItems', 'internalRemoveItems', [['k1', 'k2']], []],
            ['incrementItem', 'internalIncrementItem', ['k', 1], true],
            ['incrementItems', 'internalIncrementItems', [['k1' => 1, 'k2' => 2]], []],
            ['decrementItem', 'internalDecrementItem', ['k', 1], true],
            ['decrementItems', 'internalDecrementItems', [['k1' => 1, 'k2' => 2]], []],
            ['getCapabilities', 'internalGetCapabilities', [], $capabilities],
        ];
    }

    /**
     * @dataProvider simpleEventHandlingMethodDefinitions
     * @param mixed $retVal
     */
    public function testEventHandlingSimple(
        string $methodName,
        string $internalMethodName,
        array $methodArgs,
        $retVal
    ): void {
        $this->storage = $this->getMockForAbstractAdapter([$internalMethodName]);

        $eventList    = [];
        $eventHandler = function ($event) use (&$eventList) {
            $eventList[] = $event->getName();
        };
        $this->storage->getEventManager()->attach($methodName . '.pre', $eventHandler);
        $this->storage->getEventManager()->attach($methodName . '.post', $eventHandler);
        $this->storage->getEventManager()->attach($methodName . '.exception', $eventHandler);

        $mock = $this->storage
            ->expects($this->once())
            ->method($internalMethodName);
        $mock = call_user_func_array([$mock, 'with'], array_map([$this, 'equalTo'], $methodArgs));
        $mock->will($this->returnValue($retVal));

        call_user_func_array([$this->storage, $methodName], $methodArgs);

        $expectedEventList = [
            $methodName . '.pre',
            $methodName . '.post',
        ];
        $this->assertSame($expectedEventList, $eventList);
    }

    /**
     * @dataProvider simpleEventHandlingMethodDefinitions
     * @param mixed $retVal
     */
    public function testEventHandlingCatchException(
        string $methodName,
        string $internalMethodName,
        array $methodArgs,
        $retVal
    ): void {
        $this->storage = $this->getMockForAbstractAdapter([$internalMethodName]);

        $eventList    = [];
        $eventHandler = function ($event) use (&$eventList) {
            $eventList[] = $event->getName();
            if ($event instanceof Cache\Storage\ExceptionEvent) {
                $event->setThrowException(false);
            }
        };
        $this->storage->getEventManager()->attach($methodName . '.pre', $eventHandler);
        $this->storage->getEventManager()->attach($methodName . '.post', $eventHandler);
        $this->storage->getEventManager()->attach($methodName . '.exception', $eventHandler);

        $mock = $this->storage
            ->expects($this->once())
            ->method($internalMethodName);
        $mock = call_user_func_array([$mock, 'with'], array_map([$this, 'equalTo'], $methodArgs));
        $mock->will($this->throwException(new \Exception('test')));

        call_user_func_array([$this->storage, $methodName], $methodArgs);

        $expectedEventList = [
            $methodName . '.pre',
            $methodName . '.exception',
        ];
        $this->assertSame($expectedEventList, $eventList);
    }

    /**
     * @dataProvider simpleEventHandlingMethodDefinitions
     * @param mixed $retVal
     */
    public function testEventHandlingStopInPre(
        string $methodName,
        string $internalMethodName,
        array $methodArgs,
        $retVal
    ): void {
        $this->storage = $this->getMockForAbstractAdapter([$internalMethodName]);

        $eventList    = [];
        $eventHandler = function ($event) use (&$eventList) {
            $eventList[] = $event->getName();
        };
        $this->storage->getEventManager()->attach($methodName . '.pre', $eventHandler);
        $this->storage->getEventManager()->attach($methodName . '.post', $eventHandler);
        $this->storage->getEventManager()->attach($methodName . '.exception', $eventHandler);

        $this->storage->getEventManager()->attach($methodName . '.pre', function ($event) use ($retVal) {
            $event->stopPropagation();
            return $retVal;
        });

        // the internal method should never be called
        $this->storage->expects($this->never())->method($internalMethodName);

        // the return vaue should be available by pre-event
        $result = call_user_func_array([$this->storage, $methodName], $methodArgs);
        $this->assertSame($retVal, $result);

        // after the triggered pre-event the post-event should be triggered as well
        $expectedEventList = [
            $methodName . '.pre',
            $methodName . '.post',
        ];
        $this->assertSame($expectedEventList, $eventList);
    }

    public function testGetMetadatas(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['getMetadata', 'internalGetMetadata']);

        $meta  = ['meta' => 'data'];
        $items = [
            'key1' => $meta,
            'key2' => $meta,
        ];

        // foreach item call 'internalGetMetadata' instead of 'getMetadata'
        $this->storage->expects($this->never())->method('getMetadata');
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalGetMetadata')
            ->with($this->stringContains('key'))
            ->will($this->returnValue($meta));

        $this->assertSame($items, $this->storage->getMetadatas(array_keys($items)));
    }

    public function testGetMetadatasFail(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalGetMetadata']);

        $items = ['key1', 'key2'];

        // return false to indicate that the operation failed
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalGetMetadata')
            ->with($this->stringContains('key'))
            ->will($this->returnValue(false));

        $this->assertSame([], $this->storage->getMetadatas($items));
    }

    public function testSetItems(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['setItem', 'internalSetItem']);

        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];

        // foreach item call 'internalSetItem' instead of 'setItem'
        $this->storage->expects($this->never())->method('setItem');
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalSetItem')
            ->with($this->stringContains('key'), $this->stringContains('value'))
            ->will($this->returnValue(true));

        $this->assertSame([], $this->storage->setItems($items));
    }

    public function testSetItemsFail(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalSetItem']);

        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];

        // return false to indicate that the operation failed
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalSetItem')
            ->with($this->stringContains('key'), $this->stringContains('value'))
            ->will($this->returnValue(false));

        $this->assertSame(array_keys($items), $this->storage->setItems($items));
    }

    public function testAddItems(): void
    {
        $this->storage = $this->getMockForAbstractAdapter([
            'getItem',
            'internalGetItem',
            'hasItem',
            'internalHasItem',
            'setItem',
            'internalSetItem',
        ]);

        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];

        // first check if the items already exists using has
        // call 'internalHasItem' instead of 'hasItem' or '[internal]GetItem'
        $this->storage->expects($this->never())->method('hasItem');
        $this->storage->expects($this->never())->method('getItem');
        $this->storage->expects($this->never())->method('internalGetItem');
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalHasItem')
            ->with($this->stringContains('key'))
            ->will($this->returnValue(false));

        // If not create the items using set
        // call 'internalSetItem' instead of 'setItem'
        $this->storage->expects($this->never())->method('setItem');
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalSetItem')
            ->with($this->stringContains('key'), $this->stringContains('value'))
            ->will($this->returnValue(true));

        $this->assertSame([], $this->storage->addItems($items));
    }

    public function testAddItemsExists(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalHasItem', 'internalSetItem']);

        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        // first check if items already exists
        // -> return true to indicate that the item already exist
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalHasItem')
            ->with($this->stringContains('key'))
            ->will($this->returnValue(true));

        // set item should never be called
        $this->storage->expects($this->never())->method('internalSetItem');

        $this->assertSame(array_keys($items), $this->storage->addItems($items));
    }

    public function testAddItemsFail(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalHasItem', 'internalSetItem']);

        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        // first check if items already exists
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalHasItem')
            ->with($this->stringContains('key'))
            ->will($this->returnValue(false));

        // if not create the items
        // -> return false to indicate creation failed
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalSetItem')
            ->with($this->stringContains('key'), $this->stringContains('value'))
            ->will($this->returnValue(false));

        $this->assertSame(array_keys($items), $this->storage->addItems($items));
    }

    public function testReplaceItems(): void
    {
        $this->storage = $this->getMockForAbstractAdapter([
            'hasItem',
            'internalHasItem',
            'getItem',
            'internalGetItem',
            'setItem',
            'internalSetItem',
        ]);

        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];

        // First check if the item already exists using has
        // call 'internalHasItem' instead of 'hasItem' or '[internal]GetItem'
        $this->storage->expects($this->never())->method('hasItem');
        $this->storage->expects($this->never())->method('getItem');
        $this->storage->expects($this->never())->method('internalGetItem');
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalHasItem')
            ->with($this->stringContains('key'))
            ->will($this->returnValue(true));

        // if yes overwrite the items
        // call 'internalSetItem' instead of 'setItem'
        $this->storage->expects($this->never())->method('setItem');
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalSetItem')
            ->with($this->stringContains('key'), $this->stringContains('value'))
            ->will($this->returnValue(true));

        $this->assertSame([], $this->storage->replaceItems($items));
    }

    public function testReplaceItemsMissing(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalHasItem', 'internalSetItem']);

        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        // First check if the items already exists
        // -> return false to indicate the items doesn't exists
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalHasItem')
            ->with($this->stringContains('key'))
            ->will($this->returnValue(false));

        // writing items should never be called
        $this->storage->expects($this->never())->method('internalSetItem');

        $this->assertSame(array_keys($items), $this->storage->replaceItems($items));
    }

    public function testReplaceItemsFail(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalHasItem', 'internalSetItem']);

        $items = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        // First check if the items already exists
        // -> return true to indicate the items exists
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalHasItem')
            ->with($this->stringContains('key'))
            ->will($this->returnValue(true));

        // if yes overwrite the items
        // -> return false to indicate that overwriting failed
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalSetItem')
            ->with($this->stringContains('key'), $this->stringContains('value'))
            ->will($this->returnValue(false));

        $this->assertSame(array_keys($items), $this->storage->replaceItems($items));
    }

    public function testRemoveItems(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['removeItem', 'internalRemoveItem']);

        $keys = ['key1', 'key2'];

        // call 'internalRemoveItem' instaed of 'removeItem'
        $this->storage->expects($this->never())->method('removeItem');
        $this->storage->expects($this->exactly(count($keys)))
            ->method('internalRemoveItem')
            ->with($this->stringContains('key'))
            ->will($this->returnValue(true));

        $this->assertSame([], $this->storage->removeItems($keys));
    }

    public function testRemoveItemsFail(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalRemoveItem']);

        $keys = ['key1', 'key2', 'key3'];

        // call 'internalRemoveItem'
        // -> return false to indicate that no item was removed
        $this->storage->expects($this->exactly(count($keys)))
                       ->method('internalRemoveItem')
                       ->with($this->stringContains('key'))
                       ->will($this->returnValue(false));

        $this->assertSame($keys, $this->storage->removeItems($keys));
    }

    public function testIncrementItems(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['incrementItem', 'internalIncrementItem']);

        $items = [
            'key1' => 2,
            'key2' => 2,
        ];

        // foreach item call 'internalIncrementItem' instead of 'incrementItem'
        $this->storage->expects($this->never())->method('incrementItem');
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalIncrementItem')
            ->with($this->stringContains('key'), $this->equalTo(2))
            ->will($this->returnValue(4));

        $this->assertSame([
            'key1' => 4,
            'key2' => 4,
        ], $this->storage->incrementItems($items));
    }

    public function testIncrementItemsFail(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalIncrementItem']);

        $items = [
            'key1' => 2,
            'key2' => 2,
        ];

        // return false to indicate that the operation failed
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalIncrementItem')
            ->with($this->stringContains('key'), $this->equalTo(2))
            ->will($this->returnValue(false));

        $this->assertSame([], $this->storage->incrementItems($items));
    }

    public function testDecrementItems(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['decrementItem', 'internalDecrementItem']);

        $items = [
            'key1' => 2,
            'key2' => 2,
        ];

        // foreach item call 'internalDecrementItem' instead of 'decrementItem'
        $this->storage->expects($this->never())->method('decrementItem');
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalDecrementItem')
            ->with($this->stringContains('key'), $this->equalTo(2))
            ->will($this->returnValue(4));

        $this->assertSame([
            'key1' => 4,
            'key2' => 4,
        ], $this->storage->decrementItems($items));
    }

    public function testDecrementItemsFail(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalDecrementItem']);

        $items = [
            'key1' => 2,
            'key2' => 2,
        ];

        // return false to indicate that the operation failed
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalDecrementItem')
            ->with($this->stringContains('key'), $this->equalTo(2))
            ->will($this->returnValue(false));

        $this->assertSame([], $this->storage->decrementItems($items));
    }

    public function testTouchItems(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['touchItem', 'internalTouchItem']);

        $items = ['key1', 'key2'];

        // foreach item call 'internalTouchItem' instead of 'touchItem'
        $this->storage->expects($this->never())->method('touchItem');
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalTouchItem')
            ->with($this->stringContains('key'))
            ->will($this->returnValue(true));

        $this->assertSame([], $this->storage->touchItems($items));
    }

    public function testTouchItemsFail(): void
    {
        $this->storage = $this->getMockForAbstractAdapter(['internalTouchItem']);

        $items = ['key1', 'key2'];

        // return false to indicate that the operation failed
        $this->storage->expects($this->exactly(count($items)))
            ->method('internalTouchItem')
            ->with($this->stringContains('key'))
            ->will($this->returnValue(false));

        $this->assertSame($items, $this->storage->touchItems($items));
    }

    public function testPreEventsCanChangeArguments(): void
    {
        // getItem(s)
        $this->checkPreEventCanChangeArguments('getItem', [
            'key' => 'key',
        ], [
            'key' => 'changedKey',
        ]);

        $this->checkPreEventCanChangeArguments('getItems', [
            'keys' => ['key'],
        ], [
            'keys' => ['changedKey'],
        ]);

        // hasItem(s)
        $this->checkPreEventCanChangeArguments('hasItem', [
            'key' => 'key',
        ], [
            'key' => 'changedKey',
        ]);

        $this->checkPreEventCanChangeArguments('hasItems', [
            'keys' => ['key'],
        ], [
            'keys' => ['changedKey'],
        ]);

        // getMetadata(s)
        $this->checkPreEventCanChangeArguments('getMetadata', [
            'key' => 'key',
        ], [
            'key' => 'changedKey',
        ]);

        $this->checkPreEventCanChangeArguments('getMetadatas', [
            'keys' => ['key'],
        ], [
            'keys' => ['changedKey'],
        ]);

        // setItem(s)
        $this->checkPreEventCanChangeArguments('setItem', [
            'key'   => 'key',
            'value' => 'value',
        ], [
            'key'   => 'changedKey',
            'value' => 'changedValue',
        ]);

        $this->checkPreEventCanChangeArguments('setItems', [
            'keyValuePairs' => ['key' => 'value'],
        ], [
            'keyValuePairs' => ['changedKey' => 'changedValue'],
        ]);

        // addItem(s)
        $this->checkPreEventCanChangeArguments('addItem', [
            'key'   => 'key',
            'value' => 'value',
        ], [
            'key'   => 'changedKey',
            'value' => 'changedValue',
        ]);

        $this->checkPreEventCanChangeArguments('addItems', [
            'keyValuePairs' => ['key' => 'value'],
        ], [
            'keyValuePairs' => ['changedKey' => 'changedValue'],
        ]);

        // replaceItem(s)
        $this->checkPreEventCanChangeArguments('replaceItem', [
            'key'   => 'key',
            'value' => 'value',
        ], [
            'key'   => 'changedKey',
            'value' => 'changedValue',
        ]);

        $this->checkPreEventCanChangeArguments('replaceItems', [
            'keyValuePairs' => ['key' => 'value'],
        ], [
            'keyValuePairs' => ['changedKey' => 'changedValue'],
        ]);

        // CAS
        $this->checkPreEventCanChangeArguments('checkAndSetItem', [
            'token' => 'token',
            'key'   => 'key',
            'value' => 'value',
        ], [
            'token' => 'changedToken',
            'key'   => 'changedKey',
            'value' => 'changedValue',
        ]);

        // touchItem(s)
        $this->checkPreEventCanChangeArguments('touchItem', [
            'key' => 'key',
        ], [
            'key' => 'changedKey',
        ]);

        $this->checkPreEventCanChangeArguments('touchItems', [
            'keys' => ['key'],
        ], [
            'keys' => ['changedKey'],
        ]);

        // removeItem(s)
        $this->checkPreEventCanChangeArguments('removeItem', [
            'key' => 'key',
        ], [
            'key' => 'changedKey',
        ]);

        $this->checkPreEventCanChangeArguments('removeItems', [
            'keys' => ['key'],
        ], [
            'keys' => ['changedKey'],
        ]);

        // incrementItem(s)
        $this->checkPreEventCanChangeArguments('incrementItem', [
            'key'   => 'key',
            'value' => 1,
        ], [
            'key'   => 'changedKey',
            'value' => 2,
        ]);

        $this->checkPreEventCanChangeArguments('incrementItems', [
            'keyValuePairs' => ['key' => 1],
        ], [
            'keyValuePairs' => ['changedKey' => 2],
        ]);

        // decrementItem(s)
        $this->checkPreEventCanChangeArguments('decrementItem', [
            'key'   => 'key',
            'value' => 1,
        ], [
            'key'   => 'changedKey',
            'value' => 2,
        ]);

        $this->checkPreEventCanChangeArguments('decrementItems', [
            'keyValuePairs' => ['key' => 1],
        ], [
            'keyValuePairs' => ['changedKey' => 2],
        ]);
    }

    protected function checkPreEventCanChangeArguments(string $method, array $args, array $expectedArgs): void
    {
        $internalMethod = 'internal' . ucfirst($method);
        $eventName      = $method . '.pre';

        // init mock
        $this->storage = $this->getMockForAbstractAdapter([$internalMethod]);
        $this->storage->getEventManager()->attach($eventName, function ($event) use ($expectedArgs) {
            $params = $event->getParams();
            foreach ($expectedArgs as $k => $v) {
                $params[$k] = $v;
            }
        });

        // set expected arguments of internal method call
        $tmp    = $this->storage->expects($this->once())->method($internalMethod);
        $equals = [];
        foreach ($expectedArgs as $v) {
            $equals[] = $this->equalTo($v);
        }
        call_user_func_array([$tmp, 'with'], $equals);

        // run
        call_user_func_array([$this->storage, $method], $args);
    }

    /**
     * Generates a mock of the abstract storage adapter by mocking all abstract and the given methods
     * Also sets the adapter options
     *
     * @param array $methods
     * @return AbstractAdapter
     */
    protected function getMockForAbstractAdapter(array $methods = [])
    {
        $class = AbstractAdapter::class;

        if (! $methods) {
            $adapter = $this->getMockForAbstractClass($class);
        } else {
            $reflection = new ReflectionClass(AbstractAdapter::class);
            foreach ($reflection->getMethods() as $method) {
                if ($method->isAbstract()) {
                    $methods[] = $method->getName();
                }
            }
            $adapter = $this->getMockBuilder($class)
                ->onlyMethods(array_unique($methods))
                ->disableArgumentCloning()
                ->getMock();
        }

        $this->options = $this->options ?: new AdapterOptions();
        $adapter->setOptions($this->options);

        return $adapter;
    }
}
