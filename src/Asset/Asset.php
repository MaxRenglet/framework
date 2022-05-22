<?php

namespace Themosis\Asset;

use Themosis\Hook\IHook;
use Themosis\Html\HtmlBuilder;

class Asset implements AssetInterface
{
    protected string $handle;

    protected AssetFileInterface $file;

    protected string|bool|array $dependencies;

    protected string|bool|null $version;

    protected string|bool $argument;

    protected IHook $action;

    protected IHook $filter;

    protected HtmlBuilder $html;

    protected array $locations = [
        'wp_enqueue_scripts' => 'front',
        'admin_enqueue_scripts' => 'admin',
        'login_enqueue_scripts' => 'login',
        'customize_preview_init' => 'customizer',
        'enqueue_block_editor_assets' => 'editor',
    ];

    /**
     * Asset localized data.
     */
    protected array $localize = [];

    /**
     * Asset inline code.
     */
    protected array $inline = [];

    public function __construct(
        AssetFileInterface $file,
        IHook $action,
        IHook $filter,
        HtmlBuilder $html,
    ) {
        $this->file = $file;
        $this->action = $action;
        $this->filter = $filter;
        $this->html = $html;
    }

    /**
     * Return the asset handle.
     */
    public function getHandle(): string
    {
        return $this->handle;
    }

    /**
     * Set the asset handle.
     */
    public function setHandle(string $handle): AssetInterface
    {
        $this->handle = $handle;

        return $this;
    }

    /**
     * Return the asset file instance.
     */
    public function file(): AssetFileInterface
    {
        return $this->file;
    }

    /**
     * Return the asset path.
     */
    public function getPath(): string
    {
        return $this->file->getPath();
    }

    /**
     * Return the asset URL.
     */
    public function getUrl(): string
    {
        return $this->file->getUrl();
    }

    /**
     * Set the asset dependencies.
     */
    public function setDependencies(array $dependencies): AssetInterface
    {
        $this->dependencies = $dependencies;

        return $this;
    }

    /**
     * Return the asset dependencies.
     */
    public function getDependencies(): string|array|bool
    {
        return $this->dependencies;
    }

    /**
     * Set the asset version.
     */
    public function setVersion(string|bool|null $version): AssetInterface
    {
        $this->version = $version;

        return $this;
    }

    /**
     * Return the asset version.
     */
    public function getVersion(): string|bool|null
    {
        return $this->version;
    }

    /**
     * Set the asset type.
     * Override the auto-discovered type if any.
     */
    public function setType(string $type): AssetInterface
    {
        $path = $this->file->isExternal() ? $this->getUrl() : $this->getPath();

        $this->file->setType($path, $type);

        return $this;
    }

    /**
     * Return the asset type.
     */
    public function getType(): string|null
    {
        return $this->file->getType();
    }

    /**
     * Return the asset argument.
     */
    public function getArgument(): string|bool
    {
        return $this->argument;
    }

    /**
     * Set the asset argument.
     */
    public function setArgument(string|bool|null $arg = null): AssetInterface
    {
        if (! is_null($arg)) {
            $this->argument = $arg;

            return $this;
        }

        /**
         * If no argument is passed, but we have its type
         * then let's define some defaults.
         */
        if ('style' === $this->getType()) {
            $this->argument = 'all';
        }

        if ('script' === $this->getType()) {
            $this->argument = true;
        }

        return $this;
    }

    /**
     * Load the asset on the defined area. Default to front-end.
     */
    public function to(string|array $locations = 'front'): AssetInterface
    {
        if (is_string($locations)) {
            $locations = [$locations];
        }

        foreach ($locations as $location) {
            $hook = array_search($location, $this->locations, true);

            if ($hook) {
                $this->install($hook);
            }
        }

        return $this;
    }

    /**
     * Register the asset with appropriate action hook.
     */
    protected function install(string $hook): void
    {
        $this->action->add($hook, [$this, 'enqueue']);
    }

    /**
     * Enqueue asset.
     *
     * @throws AssetException
     */
    public function enqueue(): void
    {
        if (is_null($this->getType())) {
            throw new AssetException('The asset must have a type defined. Null given.');
        }

        if ('script' === $this->getType()) {
            $this->enqueueScript();
        } else {
            $this->enqueueStyle();
        }
    }

    /**
     * Enqueue a script asset.
     */
    protected function enqueueScript(): void
    {
        wp_enqueue_script(
            $this->getHandle(),
            $this->getUrl(),
            $this->getDependencies(),
            $this->getVersion(),
            $this->getArgument(),
        );

        if (! empty($this->localize)) {
            foreach ($this->localize as $name => $data) {
                wp_localize_script($this->getHandle(), $name, $data);
            }
        }

        if (! empty($this->inline)) {
            foreach ($this->inline as $code) {
                wp_add_inline_script($this->getHandle(), $code['code'], $code['position']);
            }
        }
    }

    /**
     * Enqueue a style asset.
     */
    protected function enqueueStyle(): void
    {
        wp_enqueue_style(
            $this->getHandle(),
            $this->getUrl(),
            $this->getDependencies(),
            $this->getVersion(),
            $this->getArgument(),
        );

        if (! empty($this->inline)) {
            foreach ($this->inline as $code) {
                wp_add_inline_style($this->getHandle(), $code['code']);
            }
        }
    }

    /**
     * Localize the asset.
     */
    public function localize(string $name, array $data): AssetInterface
    {
        $this->localize[$name] = $data;

        return $this;
    }

    /**
     * Add asset inline code.
     */
    public function inline(string $code, bool $after = true): AssetInterface
    {
        $this->inline[] = [
            'code' => $code,
            'position' => $after ? 'after' : 'before',
        ];

        return $this;
    }

    /**
     * Add asset attributes.
     *
     * @throws AssetException
     */
    public function attributes(array $attributes): AssetInterface
    {
        if (is_null($this->getType())) {
            throw new AssetException('The asset must have a type.');
        }

        $hook = 'script' === $this->getType() ? 'script_loader_tag' : 'style_loader_tag';
        $key = strtolower(trim($this->getHandle()));
        $attributes = $this->html->attributes($attributes);

        $this->filter->add($hook, function ($tag, $handle) use ($attributes, $key) {
            if ($key !== $handle) {
                return $tag;
            }

            return preg_replace('/(src|href)(.+>)/', $attributes . ' $1$2', $tag);
        });

        return $this;
    }
}
