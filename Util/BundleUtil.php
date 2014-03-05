<?php
/**
 * Symfony2 - Rapid Development Bundle.
 *
 * @package     RGies\RadBundle\Util
 * @author      Robert Gies <mail@rgies.com>
 * @copyright   Copyright © 2014 by Robert Gies
 * @license     MIT
 * @date        2014-02-04
 */

namespace RGies\GuiBundle\Util;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\Kernel;


/**
 * Class BundleUtil.
 *
 * @package RGies\GuiBundle\Util
 */
class BundleUtil
{
    /**
     * Gets list of all bundles found in src path.
     *
     * @param Controller $controller
     * @param Container $container
     * @return array
     */
    public static function getCustomBundleNameList(Controller $controller, Container $container)
    {
        $srcPath = rtrim(dirname($controller->get('kernel')->getRootDir()), '/') . '/src';
        $allBundles = $container->getParameter('kernel.bundles');
        $bundles = array();

        // find all bundles in src folder
        foreach ($allBundles as $bundle=>$path)
        {
            if (is_dir($srcPath . '/' . dirname($path)))
            {
                $bundles[] = $bundle;
            }
        }
        asort($bundles);

        return $bundles;
    }

    /**
     * Pretty-print JSON string
     *
     * Use 'format' option to select output format - currently html and txt supported, txt is default
     * Use 'indent' option to override the indentation string set in the format - by default for the 'txt' format it's a tab
     *
     * @author http://www.molengo.com/blog/article/215/title/PHP-JSON-pretty-print
     *
     * @param string $json Original JSON string
     * @param array $options Encoding options
     *
     * @return string Returns pretty JSON string
     */
    public static function getPrettyJson($json, $options = array())
    {
        $tokens = preg_split('|([\{\}\]\[,])|', $json, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = '';
        $indent = 0;

        $format = 'txt';
        $ind = "    ";

        if (isset($options['format']))
        {
            $format = $options['format'];
        }

        switch ($format)
        {
            case 'html':
                $lineBreak = '<br />';
                $ind = '&nbsp;&nbsp;&nbsp;&nbsp;';
                break;
            default:
            case 'txt':
                $lineBreak = PHP_EOL;
                $ind = "    ";
                break;
        }

        // override the defined indent setting with the supplied option
        if (isset($options['indent'])) {
            $ind = $options['indent'];
        }

        $inLiteral = false;
        foreach ($tokens as $token)
        {
            if ($token == '')
            {
                continue;
            }

            $prefix = str_repeat($ind, $indent);
            if (!$inLiteral && ($token == '{' || $token == '['))
            {
                $indent++;
                if (($result != '') && ($result[(strlen($result) - 1)] == $lineBreak)) {
                    $result .= $prefix;
                }
                $result .= $token . $lineBreak;
            }
            elseif (!$inLiteral && ($token == '}' || $token == ']'))
            {
                $indent--;
                $prefix = str_repeat($ind, $indent);
                $result .= $lineBreak . $prefix . $token;
            }
            elseif (!$inLiteral && $token == ',')
            {
                $result .= $token . $lineBreak;
            }
            else
            {
                $result .= ( $inLiteral ? '' : $prefix ) . $token;

                // Count # of unescaped double-quotes in token, subtract # of
                // escaped double-quotes and if the result is odd then we are
                // inside a string literal
                if ((substr_count($token, "\"") - substr_count($token, "\\\"")) % 2 != 0)
                {
                    $inLiteral = !$inLiteral;

                }
            }
        }

        // Some replacements
        $result = str_replace('_empty_', '', $result);
        $result = str_replace('__empty__', '', $result);
        $result = str_replace('\/', '/', $result);
        $result = str_replace('":"', '": "', $result);
        $result = str_replace('":[', '": [', $result);
        $result = str_replace('":{', '": {', $result);

        return $result;
    }

    /**
     *
     * @param array     $bundles    All bundles
     * @param string    $bundleName Bundle name to search for
     *
     * @return bool Returns TRUE if we found bundle; otherwise FALSE
     */
    public static function bundleInstalled(array $bundles, $bundleName)
    {
        $installed = FALSE;

        if (!is_array($bundles))
        {
            return $installed;
        }

        foreach ($bundles as $key => $bundle)
        {
            if ($bundle === $bundleName)
            {
                $installed = TRUE;
                break;
            }
        }

        return $installed;
    }

    /**
     * Clear the application
     *
     * @param Kernel $kernel
     *
     * @return int
     */
    public static function clearCache(Kernel $kernel)
    {
        $input = new ArgvInput(array('console', 'cache:clear'));

        $application = new Application($kernel);
        $ret = $application->run($input);

        unset($input, $application);

        return $ret;
    }
}