<?php

class pakeMidgardMvcComponent
{
    // $components is reference, because we need to update it
    public static function install_mvc_components(array &$components, $target_dir)
    {
        foreach ($components as $component => $sources) {
            $number = self::get_mvc_component($component, $sources, $target_dir);

            if ($number == -1) {
                // -1 means "already installed"
                continue;
            }

            // leaving only successful source in application.yml
            $components[$component] = array($sources[$number]);

            // Checking validity
            $component_dir = $target_dir.'/'.$component;
            $manifest = pakeYaml::loadFile($component_dir.'/manifest.yml');

            // Install pear-dependencies
            if (isset($manifest['requires_pear'])) {
                self::install_pear_dependencies($manifest['requires_pear']);
            }

            // Install component dependencies too
            if (isset($manifest['requires'])) {
                self::install_mvc_components($manifest['requires'], $target_dir);
            }

            // Finally, install component
            self::install_mvc_component($component, $target_dir);
        }
    }

    public static function update_mvc_components(array $components, $target_dir)
    {
        foreach ($components as $component => $sources) {
            self::update_mvc_component($component, $sources[0], $target_dir);

            // Checking validity
            $component_dir = $target_dir.'/'.$component;
            $manifest = pakeYaml::loadFile($component_dir.'/manifest.yml');

            // Install pear-dependencies
            if (isset($manifest['requires_pear'])) {
                self::install_pear_dependencies($manifest['requires_pear']);
            }

            // Install component dependencies too
            if (isset($manifest['requires'])) {
                self::update_mvc_components($manifest['requires'], $target_dir);
            }

            self::install_mvc_component($component, $target_dir);
        }
    }


    private static function install_mvc_component($component, $target_dir)
    {
        $component_dir = $target_dir.'/'.$component;

        // Link schemas
        $xmls = pakeFinder::type('file')->name('*.xml')->maxdepth(0);

        foreach ($xmls->in($component_dir.'/models') as $schema_file) {
            pake_symlink($schema_file, "{$target_dir}/share/schema/{$component}_" . basename($schema_file));
        }

        foreach ($xmls->in($component_dir.'/models/views') as $view_file) {
            pake_symlink($view_file, "{$target_dir}/share/views/{$component}_" . basename($view_file));
        }
    }


    private static function get_mvc_component($component, $sources, $target_dir)
    {
        $component_dir = $target_dir.'/'.$component;

        if (file_exists($component_dir)) {
            // already installed
            return -1;
        }

        if (!is_array($sources)) {
            throw new pakeException("Cannot install {$component}, source repository not provided");
        }

        // support for single-source components
        if (!isset($sources[0])) {
            $sources = array($sources);
        }

        foreach ($sources as $number => $source) {
            if (!isset($source['type'])) {
                pake_echo_error('source does not have "type" defined. skipping');
                continue;
            }

            try {
                switch ($source['type']) {
                    case 'git':
                        self::get_mvc_component_from_git($source['url'], $source['branch'], $component_dir);
                    break;

                    case 'github':
                        $is_private = isset($source['private']) ? $source['private'] : false;
                        self::get_mvc_component_from_github($source['user'], $source['repository'], $source['branch'], $is_private, $component_dir);
                    break;

                    case 'subversion':
                        self::get_mvc_component_from_subversion($source['url'], $component_dir);
                    break;

                    default:
                        pake_echo_error('source is of unknown type. skipping');
                    break;
                }

                // there wasn't exception, so, probably, we're ok
                break;
            } catch (pakeException $e) {
                pake_echo_error('there was an error fetching from source: '.$e->getMessage().'. skipping');

                if (file_exists($component_dir)) {
                    pake_echo_comment("Cleanup…");
                    pake_remove_dir($component_dir);
                    pake_echo_comment("<- Cleanup is done");
                }
            }
        }

        if (!file_exists($component_dir)) {
            throw new pakeException('Couldn\'t install "'.$component.'" component. All sources failed');
        }

        return $number;
    }

    private static function get_mvc_component_from_git($url, $branch, $component_dir)
    {
        // Check out the component from git
        pakeGit::clone_repository($url, $component_dir)->checkout($branch);
    }

    private static function get_mvc_component_from_github($user, $repository, $branch, $is_private, $component_dir)
    {
        // private repository (we can't support HTTP here)
        if ($is_private) {
            self::get_mvc_component_from_git('git@github.com:'.$user.'/'.$repository.'.git', $branch, $component_dir);
            return;
        }

        // public repository
        try {
            // At first, we try "git" protocol
            self::get_mvc_component_from_git('git://github.com/'.$user.'/'.$repository.'.git', $branch, $component_dir);
        } catch (pakeException $e) {
            // Then fallback to http
            self::get_mvc_component_from_git('https://github.com/'.$user.'/'.$repository.'.git', $branch, $component_dir);
        }
    }

    private static function get_mvc_component_from_subversion($url, $component_dir)
    {
        pakeSubversion::checkout($url, $component_dir);
    }


    private static function update_mvc_component($component, $source, $target_dir)
    {
        $component_dir = $target_dir.'/'.$component;

        if (!file_exists($component_dir)) {
            // not installed. someome lost it!
            self::get_mvc_component($component, $source, $target_dir);
            return true;
        }

        if (!is_array($source)) {
            throw new pakeException("Cannot install {$component}, source repository not provided");
        }

        if (!isset($source['type'])) {
            throw new pakeException('Couldn\'t update "'.$component.'" component: source does not have "type" defined');
        }

        try {
            switch ($source['type']) {
                case 'git':
                case 'github':
                    $git = new pakeGit($component_dir);
                    $git->pull('origin', $source['branch']);
                break;

                case 'subversion':
                    pakeSubversion::update($component_dir);
                break;

                default:
                    pake_echo_error('source is of unknown type. skipping');
                break;
            }
        } catch (pakeException $e) {
            throw new pakeException('Couldn\'t update "'.$component.'" component: '.$e->getMessage());
        }
    }

    private static function install_pear_dependencies($dependencies)
    {
        $pear = escapeshellarg(pake_which('pear'));

        foreach($dependencies as $name => $fields) {
            if (isset($fields['channel'])) {
                pakePearTask::install_pear_package($name, $fields['channel']);
            } elseif (isset($fields['url'])) {
                pakePearTask::install_from_file($fields['url'], $name, 'pear.php.net');
            } else {
                throw new pakeException('Do not know how to install pear-package without channel or url: "'.$name.'"');
            }
        }
    }
}
