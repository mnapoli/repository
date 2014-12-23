<?php

/*
 * This file is part of the puli/repository package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Repository;

use Assert\Assertion;
use InvalidArgumentException;
use Puli\Repository\Resource\Collection\ArrayResourceCollection;
use Puli\Repository\Resource\Collection\ResourceCollection;
use Puli\Repository\Resource\DirectoryResource;
use Puli\Repository\Resource\Resource;
use Puli\Repository\Resource\VirtualDirectoryResource;
use Puli\Repository\Selector\Selector;
use Webmozart\PathUtil\Path;

/**
 * An in-memory resource repository.
 *
 * Resources can be added with the method {@link add()}:
 *
 * ```php
 * use Puli\Repository\InMemoryRepository;
 *
 * $repo = new InMemoryRepository();
 * $repo->add('/css', new LocalDirectoryResource('/path/to/project/res/css'));
 * ```
 *
 * Alternatively, another repository can be passed as "backend". The paths of
 * this backend can be passed to the second argument of {@link add()}. By
 * default, a {@link FilesystemRepository} is used:
 *
 * ```php
 * use Puli\Repository\InMemoryRepository;
 *
 * $repo = new InMemoryRepository();
 * $repo->add('/css', '/path/to/project/res/css');
 * ```
 *
 * You can also create the backend manually and pass it to the constructor:
 *
 * ```php
 * use Puli\Repository\FilesystemRepository;
 * use Puli\Repository\InMemoryRepository;
 *
 * $backend = new FilesystemRepository('/path/to/project');
 *
 * $repo = new InMemoryRepository($backend)
 * $repo->add('/css', '/res/css');
 * ```
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class InMemoryRepository implements ManageableRepository
{
    /**
     * @var Resource[]|DirectoryResource[]
     */
    private $resources = array();

    /**
     * @var ResourceRepository
     */
    private $backend;

    /**
     * Creates a new repository.
     *
     * The backend repository is used to lookup the paths passed to the
     * second argument of {@link add}. If none is passed, a
     * {@link FilesystemRepository} will be used.
     *
     * @param ResourceRepository $backend The backend repository.
     *
     * @see ResourceRepository
     */
    public function __construct(ResourceRepository $backend = null)
    {
        $this->backend = $backend ?: new FilesystemRepository();
        $this->resources['/'] = new VirtualDirectoryResource('/');
        $this->resources['/']->attachTo($this);
    }

    /**
     * {@inheritdoc}
     */
    public function get($path)
    {
        Assertion::string($path, 'The path must be a string. Got: %2$s');
        Assertion::notEmpty($path, 'The path must not be empty.');
        Assertion::startsWith($path, '/', 'The path %s is not absolute.');

        $path = Path::canonicalize($path);

        if (!isset($this->resources[$path])) {
            throw ResourceNotFoundException::forPath($path);
        }

        return $this->resources[$path];
    }

    /**
     * {@inheritdoc}
     */
    public function find($selector)
    {
        Assertion::string($selector, 'The selector must be a string. Got: %2$s');
        Assertion::notEmpty($selector, 'The selector must not be empty.');
        Assertion::startsWith($selector, '/', 'The selector %s is not absolute.');

        $selector = Path::canonicalize($selector);
        $staticPrefix = Selector::getStaticPrefix($selector);
        $resources = array();

        if (strlen($selector) > strlen($staticPrefix)) {
            $regExp = Selector::toRegEx($selector);

            foreach ($this->resources as $path => $resource) {
                // strpos() is slightly faster than substr() here
                if (0 !== strpos($path, $staticPrefix)) {
                    continue;
                }

                if (!preg_match($regExp, $path)) {
                    continue;
                }

                $resources[] = $resource;
            }
        } elseif (isset($this->resources[$selector])) {
            $resources[] = $this->resources[$selector];
        }

        return new ArrayResourceCollection($resources);
    }

    /**
     * {@inheritdoc}
     */
    public function contains($selector)
    {
        Assertion::string($selector, 'The selector must be a string. Got: %2$s');
        Assertion::notEmpty($selector, 'The selector must not be empty.');
        Assertion::startsWith($selector, '/', 'The selector %s is not absolute.');

        $selector = Path::canonicalize($selector);
        $staticPrefix = Selector::getStaticPrefix($selector);

        if (strlen($selector) > strlen($staticPrefix)) {
            $regExp = Selector::toRegEx($selector);

            foreach ($this->resources as $path => $resource) {
                // strpos() is slightly faster than substr() here
                if (0 !== strpos($path, $staticPrefix)) {
                    continue;
                }

                if (!preg_match($regExp, $path)) {
                    continue;
                }

                return true;
            }

            return false;
        }

        return isset($this->resources[$selector]);
    }

    /**
     * {@inheritdoc}
     *
     * If a path is passed as second argument, the added resources are fetched
     * from the backend passed to {@link __construct}.
     *
     * @param string                             $path     The path at which to
     *                                                     add the resource.
     * @param string|Resource|ResourceCollection $resource The resource(s) to
     *                                                     add at that path.
     *
     * @throws InvalidArgumentException If the path is invalid. The path must be
     *                                  a non-empty string starting with "/".
     * @throws UnsupportedResourceException If the resource is invalid.
     */
    public function add($path, $resource)
    {
        Assertion::string($path, 'The path must be a string. Got: %2$s');
        Assertion::notEmpty($path, 'The path must not be empty.');
        Assertion::startsWith($path, '/', 'The path %s is not absolute.');

        $path = Path::canonicalize($path);

        if (is_string($resource)) {
            // Use find() only if the string is actually a selector. We want
            // deterministic results when using a selector, even if the selector
            // just matches one result.
            // See https://github.com/puli/puli/issues/17
            if (Selector::isSelector($resource)) {
                $resource = $this->backend->find($resource);
            } else {
                $resource = $this->backend->get($resource);
            }
        }

        if ($resource instanceof ResourceCollection) {
            $this->ensureDirectoryExists($path);
            foreach ($resource as $entry) {
                $this->addResource($path.'/'.$entry->getName(), $entry);
            }

            // Keep the resources sorted by file name
            ksort($this->resources);

            return;
        }

        if ($resource instanceof Resource) {
            $this->ensureDirectoryExists(Path::getDirectory($path));
            $this->addResource($path, $resource);

            ksort($this->resources);

            return;
        }

        throw new UnsupportedResourceException(sprintf(
            'The passed resource must be a string, Resource or '.
            'ResourceCollection. Got: %s',
            is_object($resource) ? get_class($resource) : gettype($resource)
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function remove($selector)
    {
        Assertion::string($selector, 'The selector must be a string. Got: %2$s');
        Assertion::notEmpty($selector, 'The selector must not be empty.');
        Assertion::startsWith($selector, '/', 'The selector %s is not absolute.');

        $selector = Path::canonicalize($selector);

        Assertion::notEq('/', $selector, 'The root directory cannot be removed.');

        $staticPrefix = Selector::getStaticPrefix($selector);
        $pathsToRemove = array();
        $removed = 0;

        // Is there a dynamic part ("*") in the selector?
        if (strlen($selector) > strlen($staticPrefix)) {
            $regExp = Selector::toRegEx($selector);

            foreach ($this->resources as $path => $resource) {
                // strpos() is slightly faster than substr() here
                if (0 !== strpos($path, $staticPrefix)) {
                    continue;
                }

                if (!preg_match($regExp, $path)) {
                    continue;
                }

                $pathsToRemove[] = $path;
            }
        } else {
            $pathsToRemove[] = $selector;
        }

        foreach ($pathsToRemove as $path) {
            // Skip resources that have already been removed
            if (isset($this->resources[$path])) {
                $this->removeResource($this->resources[$path], $removed);
            }
        }

        return $removed;
    }

    /**
     * {@inheritdoc}
     */
    public function listDirectory($path)
    {
        Assertion::string($path, 'The path must be a string. Got: %2$s');
        Assertion::notEmpty($path, 'The path must not be empty.');
        Assertion::startsWith($path, '/', 'The path %s is not absolute.');

        $path = Path::canonicalize($path);

        if (!isset($this->resources[$path])) {
            throw ResourceNotFoundException::forPath($path);
        }

        if (!$this->resources[$path] instanceof DirectoryResource) {
            throw NoDirectoryException::forPath($path);
        }

        $staticPrefix = rtrim($path, '/').'/';
        $regExp = '~^'.preg_quote($staticPrefix, '~').'[^/]+$~';
        $resources = array();

        foreach ($this->resources as $path => $resource) {
            // strpos() is slightly faster than substr() here
            if (0 !== strpos($path, $staticPrefix)) {
                continue;
            }

            if (!preg_match($regExp, $path)) {
                continue;
            }

            $resources[] = $resource;
        }

        return new ArrayResourceCollection($resources);
    }

    /**
     * Recursively creates a directory for a path.
     *
     * @param string $path A directory path.
     *
     * @throws NoDirectoryException If a resource with that path exists, but is
     *                              no directory.
     */
    private function ensureDirectoryExists($path)
    {
        if (!isset($this->resources[$path])) {
            // Recursively initialize parent directories
            if ($path !== '/') {
                $this->ensureDirectoryExists(Path::getDirectory($path));
            }

            $this->resources[$path] = new VirtualDirectoryResource($path);
            $this->resources[$path]->attachTo($this);

            return;
        }

        if (!$this->resources[$path] instanceof DirectoryResource) {
            throw NoDirectoryException::forPath($path);
        }
    }

    private function addResource($path, Resource $resource)
    {
        // Don't modify resources attached to other repositories
        if ($resource->isAttached()) {
            $resource = clone $resource;
        }

        if (isset($this->resources[$path])) {
            // If a resource with the same path was previously registered,
            // override it
            $resource->override($this->resources[$path]);
        }

        // Add the resource before adding nested resources, so that the
        // array stays sorted
        $this->resources[$path] = $resource;

        $basePath = '/' === $path ? $path : $path.'/';

        // Recursively attach directory contents
        if ($resource instanceof DirectoryResource) {
            foreach ($resource->listEntries() as $name => $entry) {
                $this->addResource($basePath.$name, $entry);
            }
        }

        // Attach resource to locator *after* calling listDirectory() and
        // override(), because these methods may depend on the previously
        // attached repository
        $resource->attachTo($this, $path);
    }

    private function removeResource(Resource $resource, &$counter)
    {
        // Recursively register directory contents
        if ($resource instanceof DirectoryResource) {
            foreach ($this->listDirectory($resource->getPath()) as $entry) {
                $this->removeResource($entry, $counter);
            }
        }

        unset($this->resources[$resource->getPath()]);

        // Detach from locator
        $resource->detach($this);

        // Keep track of the number of removed resources
        ++$counter;
    }
}