<?php

class pakeMidgardMvcComponent
{
    public static function install_mvc_components(array $components, $target_dir)
    {
        foreach ($components as $component => $sources) {
            self::get_mvc_component($component, $sources, $target_dir);
            self::install_mvc_component($component, $sources, $target_dir);
        }
    }


    private static function install_mvc_component($component, $sources, $target_dir)
    {
        $component_dir = $target_dir.'/'.$component;

        // Checking validity
        $manifest = pakeYaml::loadFile($component_dir.'/manifest.yml');

        // Link schemas
        $xmls = pakeFinder::type('file')->name('*.xml')->maxdepth(0);

        foreach ($xmls->in($component_dir.'/models') as $schema_file) {
            pake_symlink($schema_file, "{$target_dir}/share/schema/{$component}_" . basename($schema_file));
        }

        foreach ($xmls->in($component_dir.'/models/views') as $view_file) {
            pake_symlink($view_file, "{$target_dir}/share/views/{$component}_" . basename($view_file));
        }

        // Install pear-dependencies
        if (isset($manifest['requires_pear'])) {
            $pear = escapeshellarg(pake_which('pear'));

            foreach($manifest['requires_pear'] as $name => $fields) {
                if (isset($fields['channel'])) {
                    pakePearTask::install_pear_package($name, $fields['channel']);
                } elseif (isset($fields['url'])) {
                    pakePearTask::install_from_file($fields['url'], $name, 'pear.php.net');
                } else {
                    throw new pakeException('Do not know how to install pear-package without channel or url: "'.$name.'"');
                }
            }
        }

        // Install component dependencies too
        if (isset($manifest['requires'])) {
            self::install_mvc_components($manifest['requires'], $target_dir);
        }
    }

    private static function get_mvc_component($component, $sources, $target_dir)
    {
        $component_dir = $target_dir.'/'.$component;

        if (file_exists($component_dir)) {
            // already installed
            return true;
        }

        if (!is_array($sources)) {
            throw new pakeException("Cannot install {$component}, source repository not provided");
        }

        // support for single-source components
        if (!isset($sources[0])) {
            $sources = array($sources);
        }

        foreach ($sources as $source) {
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
}
