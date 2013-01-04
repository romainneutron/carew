<?php

namespace Carew\Console\Command;

use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Carew\Processor\Processor;
use Carew\Event\Events;

class Build extends BaseCommand
{
    private $container;

    public function __construct(\Pimple $container)
    {
        $this->container = $container;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('carew:build')
            ->setDescription('Builds static html files from markdown source')
            ->setDefinition(array(
                new InputOption('web-dir', null, InputOption::VALUE_REQUIRED, 'Where to write generated content', $this->container['web_dir']),
                new InputOption('base-dir', null, InputOption::VALUE_REQUIRED, 'Where locate your content', $this->container['base_dir']),
            ))
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->container['base_dir'] = $baseDir = $input->getOption('base-dir');
        $this->container['web_dir'] = $webDir = $input->getOption('web-dir');

        $processor = new Processor($this->container['event_dispatcher'], $input, $output);

        $posts = $processor->process($baseDir.'/posts', '*-*-*-*.md', array(Events::POST));
        $posts = $processor->sortByDate($posts);
        $pages = $processor->process($baseDir.'/pages', '*.md', array(Events::PAGE));
        $api = $processor->process($baseDir.'/api', '*', array(Events::API), true);

        $documents = array_merge($posts, $pages, $api);

        $tags       = $processor->buildCollection($documents, 'tags');
        $navigation = $processor->buildCollection($documents, 'navigation');

        $this->container['filesystem']->remove($this->container['finder']->in($webDir)->exclude(basename(realpath($baseDir))));

        $this->container['twigGlobales'] = array_replace($this->container['twigGlobales'], array(
            'latest'       => reset($posts),
            'navigation'   => $navigation,
            'pages'        => $pages,
            'posts'        => $posts,
            'tags'         => $tags,
        ));

        $builder = $this->container['builder'];
        foreach ($documents as $document) {
            if ($input->getOption('verbose')) {
                $output->writeln(sprintf('Building <info>%s</info>', $document->getPath()));
            }
            $builder->buildDocument($document);
        }

        // Build Tags
        if ($input->getOption('verbose')) {
            $output->writeln('Building <info>Tags</info>');
        }

        foreach ($this->container['finder']->in($baseDir.'/layouts/')->files()->name('tags.*.twig') as $file) {
            $file = $file->getBasename();

            preg_match('#tags\.(.+?)\.twig$#', $file, $match);
            $format = $match[1];

            foreach ($tags as $tag => $posts) {
                $path = sprintf('tags/%s.%s', $tag, $format);
                $vars = array(
                    'document'     => array(
                        'path'  => $path,
                        'title' => 'Tags: '.$tag,
                    ),
                    'posts'        => $posts,
                    'tag'          => $tag,
                    'relativeRoot' => '..',
                    'currentPath'  => $path,
                );
                $rendered = $twig->render($file, $vars);
                $target = sprintf('%s/%s',$webDir, $path);
                $this->container['filesystem']->mkdir(dirname($target));
                file_put_content($target, $rendered);
            }
        }

        if ($input->getOption('verbose')) {
            $output->writeln('Building <info>Index</info>');
        }

        foreach ($this->container['finder']->in($baseDir.'/layouts/')->files()->name('index.*.twig') as $file) {
            $file = $file->getBasename();

            preg_match('#index\.(.+?)\.twig$#', $file, $match);
            $format = $match[1];

            $path = 'index.'.$format;
            $vars = array(
                'document'     => array(
                    'path'  => $path,
                    'title' => isset($this->container['config']['site']) ? (isset($this->container['config']['site']['title']) ? $this->container['config']['site']['title'] : '' ): '',
                ),
                'relativeRoot' => '.',
                'currentPath'  => $path,
            );
            $rendered = $twig->render($file, $vars);
            $target = "$webDir/$path";
            file_put_content($target, $rendered);
        }

        if (isset($this->container['config']['engine']['theme_path'])) {
            $themePath = str_replace('%dir%', $baseDir, $this->container['config']['engine']['theme_path']);
            if (isset($themePath) && is_dir($themePath.'/assets')) {
                $this->container['filesystem']->mirror($themePath.'/assets/', $webDir.'/');
            }
        }

        if (is_dir($baseDir.'/assets')) {
            $this->container['filesystem']->mirror($baseDir.'/assets/', $webDir.'/', null, array('override' => true));
        }
    }
}
