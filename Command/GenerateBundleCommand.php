<?php

/**
 * Symfony2 - Rapid Development Bundle.
 *
 * @package     RGies\GuiBundle\Command
 * @author      Robert Gies <mail@rgies.com>
 * @copyright   Copyright © 2014 by Robert Gies
 * @license     MIT
 * @date        2014-06-19
 */

namespace RGies\GuiBundle\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\HttpKernel\KernelInterface;
use Sensio\Bundle\GeneratorBundle\Manipulator\KernelManipulator;
use Sensio\Bundle\GeneratorBundle\Manipulator\RoutingManipulator;
use Sensio\Bundle\GeneratorBundle\Command\Helper\QuestionHelper;
use RGies\GuiBundle\Generator\BundleGenerator;
use Sensio\Bundle\GeneratorBundle\Command\GeneratorCommand;
use Sensio\Bundle\GeneratorBundle\Command\Validators;

/**
 * Generates bundles.
 *
 * @author Robert Gies <mail@rgies.com>
 */
class GenerateBundleCommand extends GeneratorCommand
{
    /**
     * @see Command
     */
    protected function configure()
    {
        $this
            ->setDefinition(array(
                new InputOption('namespace', '', InputOption::VALUE_REQUIRED, 'The namespace of the bundle to create'),
                new InputOption('dir', '', InputOption::VALUE_REQUIRED, 'The directory where to create the bundle'),
                new InputOption('bundle-name', '', InputOption::VALUE_REQUIRED, 'The optional bundle name'),
                new InputOption('format', '', InputOption::VALUE_REQUIRED, 'Use the format for configuration files (php, xml, yml, or annotation)'),
                new InputOption('structure', '', InputOption::VALUE_NONE, 'Whether to generate the whole directory structure'),
                new InputOption('template-name', '', InputOption::VALUE_OPTIONAL, 'The optional bundle template name'),
            ))
            ->setDescription('Generates a bundle')
            ->setHelp(<<<EOT
The <info>generate:bundle</info> command helps you generates new bundles.

By default, the command interacts with the developer to tweak the generation.
Any passed option will be used as a default value for the interaction
(<comment>--namespace</comment> is the only one needed if you follow the
conventions):

<info>php app/console gui:generate:bundle --namespace=Acme/BlogBundle</info>

Note that you can use <comment>/</comment> instead of <comment>\\ </comment>for the namespace delimiter to avoid any
problem.

If you want to disable any user interaction, use <comment>--no-interaction</comment> but don't forget to pass all needed options:

<info>php app/console gui:generate:bundle --namespace=Acme/BlogBundle --dir=src [--bundle-name=...] --no-interaction</info>

Note that the bundle namespace must end with "Bundle".
EOT
            )
            ->setName('gui:generate:bundle')
        ;
    }

    /**
     * @see Command
     *
     * @throws \InvalidArgumentException When namespace doesn't end with Bundle
     * @throws \RuntimeException         When bundle can't be executed
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $questionHelper = $this->getQuestionHelper();

        if ($input->isInteractive()) {
            if (!$questionHelper->ask($input, $output, new ConfirmationQuestion($questionHelper->getQuestion('Do you confirm generation', 'yes', '?'), true))) {
                $output->writeln('<error>Command aborted</error>');

                return 1;
            }
        }

        foreach (array('namespace', 'dir') as $option) {
            if (null === $input->getOption($option)) {
                throw new \RuntimeException(sprintf('The "%s" option must be provided.', $option));
            }
        }

        $namespace = Validators::validateBundleNamespace($input->getOption('namespace'));
        if (!$bundle = $input->getOption('bundle-name')) {
            $bundle = strtr($namespace, array('\\' => ''));
        }
        $bundle = Validators::validateBundleName($bundle);
        $dir = Validators::validateTargetDir($input->getOption('dir'), $bundle, $namespace);
        if (null === $input->getOption('format')) {
            $input->setOption('format', 'annotation');
        }
        $format = Validators::validateFormat($input->getOption('format'));
        $structure = $input->getOption('structure');

        $questionHelper->writeSection($output, 'Bundle generation');

        if (!$this->getContainer()->get('filesystem')->isAbsolutePath($dir)) {
            $dir = getcwd().'/'.$dir;
        }

        // get bundle template name
        $templateName = $input->getOption('template-name');
        $templateDir = str_replace('\\', '/', realpath(__DIR__ . '/../Resources/skeleton/bundle/templates'));

        if (is_dir($templateDir . '/' . $templateName))
        {
            $templateDir .= '/' . $templateName;
        }
        else
        {
            $templateDir = null;
        }

        $generator = $this->getGenerator();
        $generator->generate($namespace, $bundle, $dir, $format, $structure, $templateDir);

        $output->writeln('Generating the bundle code: <info>OK</info>');

        $errors = array();
        $runner = $questionHelper->getRunner($output, $errors);

        // check that the namespace is already autoloaded
        $runner($this->checkAutoloader($output, $namespace, $bundle, $dir));

        // register the bundle in the Kernel class
        $runner($this->updateKernel($questionHelper, $input, $output, $this->getContainer()->get('kernel'), $namespace, $bundle));

        // routing
        $runner($this->updateRouting($questionHelper, $input, $output, $bundle, $format));

        $questionHelper->writeGeneratorSummary($output, $errors);
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $questionHelper = $this->getQuestionHelper();
        $questionHelper->writeSection($output, 'Welcome to the Symfony2 bundle generator');

        // namespace
        $namespace = null;
        try {
            $namespace = $input->getOption('namespace') ? Validators::validateBundleNamespace($input->getOption('namespace')) : null;
        } catch (\Exception $error) {
            $output->writeln($questionHelper->getHelperSet()->get('formatter')->formatBlock($error->getMessage(), 'error'));
        }

        if (null === $namespace) {
            $output->writeln(array(
                '',
                'Your application code must be written in <comment>bundles</comment>. This command helps',
                'you generate them easily.',
                '',
                'Each bundle is hosted under a namespace (like <comment>Acme/Bundle/BlogBundle</comment>).',
                'The namespace should begin with a "vendor" name like your company name, your',
                'project name, or your client name, followed by one or more optional category',
                'sub-namespaces, and it should end with the bundle name itself',
                '(which must have <comment>Bundle</comment> as a suffix).',
                '',
                'See http://symfony.com/doc/current/cookbook/bundles/best_practices.html#index-1 for more',
                'details on bundle naming conventions.',
                '',
                'Use <comment>/</comment> instead of <comment>\\ </comment> for the namespace delimiter to avoid any problem.',
                '',
            ));

            $acceptedNamespace = false;
            while (!$acceptedNamespace) {
                $question = new Question($questionHelper->getQuestion('Bundle namespace', $input->getOption('namespace')), $input->getOption('namespace'));
                $question->setValidator(function ($answer) {
                        return Validators::validateBundleNamespace($answer, false);

                });
                $namespace = $questionHelper->ask($input, $output, $question);

                // mark as accepted, unless they want to try again below
                $acceptedNamespace = true;

                // see if there is a vendor namespace. If not, this could be accidental
                if (false === strpos($namespace, '\\')) {
                    // language is (almost) duplicated in Validators
                    $msg = array();
                    $msg[] = '';
                    $msg[] = sprintf('The namespace sometimes contain a vendor namespace (e.g. <info>VendorName/BlogBundle</info> instead of simply <info>%s</info>).', $namespace, $namespace);
                    $msg[] = 'If you\'ve *did* type a vendor namespace, try using a forward slash <info>/</info> (<info>Acme/BlogBundle</info>)?';
                    $msg[] = '';
                    $output->writeln($msg);

                    $question = new ConfirmationQuestion($questionHelper->getQuestion(
                        sprintf('Keep <comment>%s</comment> as the bundle namespace (choose no to try again)?', $namespace),
                        'yes'
                    ), true);
                    $acceptedNamespace = $questionHelper->ask($input, $output, $question);
                }
            }
            $input->setOption('namespace', $namespace);
        }

        // bundle name
        $bundle = null;
        try {
            $bundle = $input->getOption('bundle-name') ? Validators::validateBundleName($input->getOption('bundle-name')) : null;
        } catch (\Exception $error) {
            $output->writeln($questionHelper->getHelperSet()->get('formatter')->formatBlock($error->getMessage(), 'error'));
        }

        if (null === $bundle) {
            //$bundle = strtr($namespace, array('\\Bundle\\' => '', '\\' => ''));
            $strItems = explode('\\', $namespace);
            $bundle = end($strItems);

            $output->writeln(array(
                '',
                'In your code, a bundle is often referenced by its name. It can be the',
                'concatenation of all namespace parts but it\'s really up to you to come',
                'up with a unique name (a good practice is to start with the vendor name).',
                'Based on the namespace, we suggest <comment>'.$bundle.'</comment>.',
                '',
            ));
            $question = new Question($questionHelper->getQuestion('Bundle name', $bundle), $bundle);
            $question->setValidator(
                 array('Sensio\Bundle\GeneratorBundle\Command\Validators', 'validateBundleName')
            );
            $bundle = $questionHelper->ask($input, $output, $question);
            $input->setOption('bundle-name', $bundle);
        }

        // target dir
        $dir = null;
        try {
            $dir = $input->getOption('dir') ? Validators::validateTargetDir($input->getOption('dir'), $bundle, $namespace) : null;
        } catch (\Exception $error) {
            $output->writeln($questionHelper->getHelperSet()->get('formatter')->formatBlock($error->getMessage(), 'error'));
        }

        if (null === $dir) {
            $dir = dirname($this->getContainer()->getParameter('kernel.root_dir')).'/src';

            $output->writeln(array(
                '',
                'The bundle can be generated anywhere. The suggested default directory uses',
                'the standard conventions.',
                '',
            ));
            $question = new Question($questionHelper->getQuestion('Target directory', $dir), $dir);
            $question->setValidator(function ($dir) use ($bundle, $namespace) {
                    return Validators::validateTargetDir($dir, $bundle, $namespace);
            });
            $dir = $questionHelper->ask($input, $output, $question);
            $input->setOption('dir', $dir);
        }

        // format
        $format = null;
        try {
            $format = $input->getOption('format') ? Validators::validateFormat($input->getOption('format')) : null;
        } catch (\Exception $error) {
            $output->writeln($questionHelper->getHelperSet()->get('formatter')->formatBlock($error->getMessage(), 'error'));
        }

        if (null === $format) {
            $output->writeln(array(
                '',
                'Determine the format to use for the generated configuration.',
                '',
            ));
            $question = new Question($questionHelper->getQuestion('Configuration format (yml, xml, php, or annotation) [annotation]', $input->getOption('format')), 'annotation');
            $question->setValidator(
                array('Sensio\Bundle\GeneratorBundle\Command\Validators', 'validateFormat')
            );
            $format = $questionHelper->ask($input, $output, $question);
            $input->setOption('format', $format);
        }

        // optional files to generate
        $output->writeln(array(
            '',
            'To help you get started faster, the command can generate some',
            'code snippets for you.',
            '',
        ));

        $structure = $input->getOption('structure');
        $question = new ConfirmationQuestion($questionHelper->getQuestion('Do you want to generate the whole directory structure', 'no', '?'), false);
        $template = '';
        if (!$structure && $questionHelper->ask($input, $output, $question))
        {
            $structure = true;

            $output->writeln(array(
                '',
                '',
                'Optional you can set a following bundle template name,',
                'as target for the used code snippets:',
                '[' . implode(',', $this->_getBundleTemplateNames()) . ']',
                '',
            ));
            // ask for bundle template name
            //$choice = $this->getBundleTemplateSelection();

            $question = new Question($questionHelper->getQuestion('Bundle template', $input->getOption('template-name')), $input->getOption('template-name'));
            $question->setValidator(
                array('RGies\GuiBundle\Command\GenerateBundleCommand', 'validateBundleTemplate')
            );
            $template = $questionHelper->ask($input, $output, $question);

            //$template = $dialog->askAndValidate($output, $dialog->getQuestion('Bundle template', $input->getOption('template-name')), array('RGies\GuiBundle\Command\GenerateBundleCommand', 'validateBundleTemplate'), false, $input->getOption('template-name'));
            $input->setOption('template-name', $template);
        }
        $input->setOption('structure', $structure);

        // summary
        $output->writeln(array(
            '',
            $this->getHelper('formatter')->formatBlock('Summary before generation', 'bg=blue;fg=white', true),
            '',
            sprintf("You are going to generate a \"<info>%s\\%s</info>\" bundle\nin \"<info>%s</info>\" using the \"<info>%s</info>\" format.", $namespace, $bundle, $dir, $format, $template),
            '',
        ));
    }

    protected function checkAutoloader(OutputInterface $output, $namespace, $bundle, $dir)
    {
        $output->write('Checking that the bundle is autoloaded: ');
        if (!class_exists($namespace.'\\'.$bundle)) {
            return array(
                '- Edit the <comment>composer.json</comment> file and register the bundle',
                '  namespace in the "autoload" section:',
                '',
            );
        }
    }

    protected function updateKernel(QuestionHelper $questionHelper, InputInterface $input, OutputInterface $output, KernelInterface $kernel, $namespace, $bundle)
    {
        $auto = true;
        if ($input->isInteractive()) {
            $question = new ConfirmationQuestion($questionHelper->getQuestion('Confirm automatic update of your Kernel', 'yes', '?'), true);
            $auto = $questionHelper->ask($input, $output, $question);
        }

        $output->write('Enabling the bundle inside the Kernel: ');
        $manip = new KernelManipulator($kernel);
        try {
            $ret = $auto ? $manip->addBundle($namespace.'\\'.$bundle) : false;

            if (!$ret) {
                $reflected = new \ReflectionObject($kernel);

                return array(
                    sprintf('- Edit <comment>%s</comment>', $reflected->getFilename()),
                    '  and add the following bundle in the <comment>AppKernel::registerBundles()</comment> method:',
                    '',
                    sprintf('    <comment>new %s(),</comment>', $namespace.'\\'.$bundle),
                    '',
                );
            }
        } catch (\RuntimeException $e) {
            return array(
                sprintf('Bundle <comment>%s</comment> is already defined in <comment>AppKernel::registerBundles()</comment>.', $namespace.'\\'.$bundle),
                '',
            );
        }
    }

    protected function updateRouting(QuestionHelper $questionHelper, InputInterface $input, OutputInterface $output, $bundle, $format)
    {
        $auto = true;
        if ($input->isInteractive()) {
            $question = new ConfirmationQuestion($questionHelper->getQuestion('Confirm automatic update of the Routing', 'yes', '?'), true);
            $auto = $questionHelper->ask($input, $output, $question);
        }

        $output->write('Importing the bundle routing resource: ');
        $routing = new RoutingManipulator($this->getContainer()->getParameter('kernel.root_dir').'/config/routing.yml');
        try {
            $ret = $auto ? $routing->addResource($bundle, $format) : false;
            if (!$ret) {
                if ('annotation' === $format) {
                    $help = sprintf("        <comment>resource: \"@%s/Controller/\"</comment>\n        <comment>type:     annotation</comment>\n", $bundle);
                } else {
                    $help = sprintf("        <comment>resource: \"@%s/Resources/config/routing.%s\"</comment>\n", $bundle, $format);
                }
                $help .= "        <comment>prefix:   /</comment>\n";

                return array(
                    '- Import the bundle\'s routing resource in the app main routing file:',
                    '',
                    sprintf('    <comment>%s:</comment>', $bundle),
                    $help,
                    '',
                );
            }
        } catch (\RuntimeException $e) {
            return array(
                sprintf('Bundle <comment>%s</comment> is already imported.', $bundle),
                '',
            );
        }
    }

    protected function createGenerator()
    {
        return new BundleGenerator($this->getContainer()->get('filesystem'));
    }

    public static function validateBundleTemplate($name)
    {
        return $name;
    }

    protected function _getBundleTemplateNames()
    {
        $names = array();
        $path = str_replace('\\', '/', realpath(__DIR__ . '/../Resources/skeleton/bundle/templates'));

        if ($files = scandir($path))
        {
            foreach ($files as $file)
            {
                if ($file[0] != '.' && is_dir($path . '/' . $file))
                {
                    $names[] = $file;
                }
            }
        }

        return $names;
    }
}
