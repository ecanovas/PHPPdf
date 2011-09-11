<?php

/*
 * Copyright 2011 Piotr Śliwa <peter.pl7@gmail.com>
 *
 * License information is in LICENSE file
 */

namespace PHPPdf\Node;

use PHPPdf\Engine\GraphicsContext;

use PHPPdf\Document,
    PHPPdf\Util\DrawingTask,
    PHPPdf\Util\Point,
    PHPPdf\Formatter\Formatter;

/**
 * Single pdf page
 *
 * @author Piotr Śliwa <peter.pl7@gmail.com>
 */
class Page extends Container
{
    const ATTR_SIZE = 'page-size';
    const SIZE_A4 = '595:842';

    private $graphicsContext;

    /**
     * @var Node
     */
    private $footer;

    /**
     * @var Node
     */
    private $header;
    
    /**
     * @var Node
     */
    private $watermark;

    /**
     * @var PageContext;
     */
    private $context;

    private $runtimeNodes = array();

    private $preparedTemplate = false;

    public function  __construct(array $attributes = array())
    {
        parent::__construct($attributes);

        $this->initializeBoundary();
        $this->initializePlaceholders();
    }

    protected static function setDefaultAttributes()
    {
        parent::setDefaultAttributes();
        
        static::addAttribute(self::ATTR_SIZE);
        static::addAttribute('page-size', self::SIZE_A4);
        static::addAttribute('encoding', 'utf-8');
        static::addAttribute('static-size', true);
        static::addAttribute('text-align', self::ALIGN_LEFT);
        static::addAttribute('text-decoration', self::TEXT_DECORATION_NONE);
        static::addAttribute('alpha', 1);
        static::addAttribute('document-template');
    }
    
    public function initialize()
    {
        parent::initialize();
        
        $this->setAttribute('page-size', self::SIZE_A4);
    }

    protected static function initializeType()
    {
        parent::initializeType();
        static::setAttributeSetters(array('page-size' => 'setPageSize'));
    }
    
    private function initializeBoundary()
    {
        $width = $this->getRealWidth();
        $height = $this->getRealHeight();
        
        $boundary = $this->getBoundary();
        if($boundary->isClosed())
        {
            $boundary->reset();
        }
        
        $boundary->setNext(0, $height)
                 ->setNext($width, $height)
                 ->setNext($width, 0)
                 ->setNext(0, 0)
                 ->close();
                 
        foreach(array('margin-top', 'margin-bottom', 'margin-left', 'margin-right') as $name)
        {
            $value = $this->getAttribute($name);
            $this->translateMargin($name, $value);
        }
    }

    private function initializePlaceholders()
    {
        $this->setFooter(new Container(array('height' => 0)));
        $this->setHeader(new Container(array('height' => 0)));
        $this->setWatermark(new Container(array('height' => 0)));
    }

    public function setPageSize($pageSize)
    {
        $sizes = explode(':', $pageSize);

        if(count($sizes) < 2)
        {
            throw new \InvalidArgumentException(sprintf('page-size attribute should be in "width:height" format, "%s" given.', $pageSize));
        }

        list($width, $height) = $sizes;

        $this->setWidth($width);
        $this->setHeight($height);

        $this->setAttributeDirectly('page-size', $pageSize);
        
        $this->initializeBoundary();

        return $this;
    }

    protected function doDraw(Document $document)
    {
        $this->prepareGraphicsContext($document);

        $document->attachGraphicsContext($this->getGraphicsContext());

        $tasks = array();
        
        if(!$this->preparedTemplate)
        {
            $tasks = $this->getTemplateDrawingTasksAndFormatPlaceholders($document);
        }
        
        $originalTasks = parent::doDraw($document);

        $tasks = array_merge($tasks, $originalTasks);

        foreach($this->runtimeNodes as $node)
        {
            $node->evaluate();
            $runtimeTasks = $node->getDrawingTasks($document);

            $tasks = array_merge($tasks, $runtimeTasks);
        }
        
        return $tasks;
    }
    
    private function prepareGraphicsContext(Document $document)
    {
        $this->assignGraphicsContextIfIsNull($document);
    }

    private function assignGraphicsContextIfIsNull(Document $document)
    {
        if($this->graphicsContext === null)
        {
            $this->setGraphicsContext($document->createGraphicsContext($this->getAttribute(self::ATTR_SIZE)));
            $this->setGraphicsContextDefaultStyle($document);
        }
    }
    
    private function setGraphicsContext(GraphicsContext $gc)
    {
        $this->graphicsContext = $gc;
    }
    
    /**
     * @return GraphicsContext
     */
    public function getGraphicsContext()
    {
        return $this->graphicsContext;
    }

    private function setGraphicsContextDefaultStyle(Document $document)
    {
        $font = $this->getFont($document);
        if($font && $this->getAttribute('font-size'))
        {
            $this->graphicsContext->setFont($font, $this->getAttribute('font-size'));
        }

        $blackColor = '#000000';
        $this->graphicsContext->setFillColor($blackColor);
        $this->graphicsContext->setLineColor($blackColor);
    }

    public function getPage()
    {
        return $this;
    }

    public function getParent()
    {
        return null;
    }

    public function breakAt($height)
    {
        throw new \LogicException('Page can\'t be broken.');
    }

    public function copy()
    {
        $boundary = clone $this->getBoundary();
        $copy = parent::copy();
        
        if($this->graphicsContext)
        {
            $graphicsContext = $this->getGraphicsContext();
            $clonedGraphicsContext = clone $graphicsContext;
            $copy->graphicsContext = $clonedGraphicsContext;
        }

        $copy->setBoundary($boundary);

        foreach($this->runtimeNodes as $index => $node)
        {
            $clonedNode = $node->copyAsRuntime();
            $clonedNode->setPage($copy);
            $copy->runtimeNodes[$index] = $clonedNode;
        }

        return $copy;
    }

    /**
     * @return int Height without vertical margins
     */
    public function getHeight()
    {
        $verticalMargins = $this->getMarginTop() + $this->getMarginBottom();

        return (parent::getHeight() - $verticalMargins);
    }

    /**
     * @return int Width without horizontal margins
     */
    public function getWidth()
    {
        $horizontalMargins = $this->getMarginLeft() + $this->getMarginRight();

        return (parent::getWidth() - $horizontalMargins);
    }

    /**
     * @return int Height with vertical margins
     */
    public function getRealHeight()
    {
        return parent::getHeight();
    }

    /**
     * @return int Width with horizontal margins
     */
    public function getRealWidth()
    {
        return parent::getWidth();
    }
    
    public function getRealBoundary()
    {
        $boundary = clone $this->getBoundary();
        $boundary->pointTranslate(0, -$this->getMarginLeft(), -$this->getMarginTop());
        $boundary->pointTranslate(1, $this->getMarginRight(), -$this->getMarginTop());
        $boundary->pointTranslate(2, $this->getMarginRight(), $this->getMarginBottom());
        $boundary->pointTranslate(3, -$this->getMarginLeft(), $this->getMarginBottom());
        $boundary->pointTranslate(4, -$this->getMarginLeft(), -$this->getMarginTop());
        
        return $boundary;
    }

    protected function setMarginAttribute($name, $value)
    {
        $value = (int) $value;

        $diff = $value - $this->getAttribute($name);

        $this->translateMargin($name, $diff);

        return parent::setMarginAttribute($name, $value);
    }

    private function translateMargin($name, $value)
    {
        $boundary = $this->getBoundary();
        $x = $y = 0;
        if($name == 'margin-left')
        {
            $indexes = array(0, 3, 4);
            $x = $value;
        }
        elseif($name == 'margin-right')
        {
            $indexes = array(1, 2);
            $x = -$value;
        }
        elseif($name == 'margin-top')
        {
            $indexes = array(0, 1, 4);
            $y = $value;
        }
        else
        {
            $indexes = array(2, 3);
            $y = -$value;
        }

        foreach($indexes as $index)
        {
            if(isset($boundary[$index]))
            {
                $boundary->pointTranslate($index, $x, $y);
            }
        }
    }

    public function setFooter(Container $footer)
    {
        $this->throwExceptionIfHeightIsntSet($footer);
        $footer->setAttribute('static-size', true);
        $footer->setParent($this);

        $boundary = $this->getBoundary();
        $height = $footer->getHeight();

        $this->setMarginBottom($this->getMarginBottom() + $height);
        $footer->setWidth($this->getWidth());

        $footer->getBoundary()->setNext($boundary[3])
                              ->setNext($boundary[2])
                              ->setNext($boundary[2]->translate(0, $height))
                              ->setNext($boundary[3]->translate(0, $height))
                              ->close();

        $this->footer = $footer;
    }

    private function throwExceptionIfHeightIsntSet(Container $contaienr)
    {
        $height = $contaienr->getHeight();

        if($height === null || !is_numeric($height))
        {
            throw new \InvalidArgumentException('Height of header and footer must be set.');
        }
    }

    public function setHeader(Container $header)
    {
        $this->throwExceptionIfHeightIsntSet($header);
        $header->setAttribute('static-size', true);
        
        $header->setParent($this);

        $boundary = $this->getBoundary();
        $height = $header->getHeight();

        $this->setMarginTop($this->getMarginTop() + $height);
        $header->setWidth($this->getWidth());

        $header->getBoundary()->setNext($boundary[0]->translate(0, -$height))
                              ->setNext($boundary[1]->translate(0, -$height))
                              ->setNext($boundary[1])
                              ->setNext($boundary[0])
                              ->close();

        $this->header = $header;
    }
    
    public function setWatermark(Container $watermark)
    {
        $watermark->setParent($this);
        $watermark->setAttribute('vertical-align', self::VERTICAL_ALIGN_MIDDLE);
        $watermark->setHeight($this->getHeight());
        $watermark->setWidth($this->getWidth());
        
        $watermark->setBoundary(clone $this->getBoundary());

        $this->watermark = $watermark;
    }

    protected function getHeader()
    {
        return $this->header;
    }

    protected function getFooter()
    {
        return $this->footer;
    }
    
    protected function getWatermark()
    {
        return $this->watermark;
    }

    public function prepareTemplate(Document $document)
    {
        $this->prepareGraphicsContext($document);
        
        $tasks = $this->getTemplateDrawingTasksAndFormatPlaceholders($document);

        $document->invokeTasks($tasks);

        $this->preparedTemplate = true;
    }
    
    private function getTemplateDrawingTasksAndFormatPlaceholders(Document $document)
    {
        $this->formatConvertAttributes($document);
        
        $this->getHeader()->format($document);
        $this->getFooter()->format($document);
        $this->getWatermark()->format($document);

        $tasks = array();

        $tasks = array_merge($this->getDrawingTasksFromEnhancements($document), $this->footer->getDrawingTasks($document), $this->header->getDrawingTasks($document), $this->watermark->getDrawingTasks($document));

        $this->footer->removeAll();
        $this->header->removeAll();
        $this->watermark->removeAll();
        
        return $tasks;
    }
    
    protected function preDraw(Document $document)
    {
        return array();
    }

    private function formatConvertAttributes(Document $document)
    {
        $formatterName = 'PHPPdf\Formatter\ConvertAttributesFormatter';

        $formatter = $document->getFormatter($formatterName);
        $formatter->format($this, $document);
    }

    public function getContext()
    {
        if($this->context === null)
        {
            throw new \LogicException('PageContext has not been set.');
        }

        return $this->context;
    }

    public function setContext(PageContext $context)
    {
        $this->context = $context;
    }

    public function markAsRuntimeNode(Runtime $node)
    {
        $this->runtimeNodes[] = $node;
    }

    public function getPlaceholder($name)
    {
        if($name === 'footer')
        {
            return $this->getFooter();
        }
        elseif($name === 'header')
        {
            return $this->getHeader();
        }

        return null;
    }

    public function setPlaceholder($name, Node $node)
    {
        switch($name)
        {
            case 'footer':
                return $this->setFooter($node);
            case 'header':
                return $this->setHeader($node);
            case 'watermark':
                return $this->setWatermark($node);
            default:
                parent::setPlaceholder($name, $node);
        }
    }

    public function hasPlaceholder($name)
    {
        return in_array($name, array('footer', 'header', 'watermark'));
    }

    public function unserialize($serialized)
    {
        parent::unserialize($serialized);

        $this->initializePlaceholders();
    }
    
    public function setGraphicsContextFromSourceDocumentIfNecessary(Document $document)
    {
        $gc = $this->getGraphicsContextFromSourceDocument($document);
        
        if($gc !== null)
        {
            $gc = $gc->copy();
            
            $this->setGraphicsContext($gc);
            $this->setPageSize($gc->getWidth().':'.$gc->getHeight());
            $this->setGraphicsContextDefaultStyle($document);
        }
    }
    
    protected function beforeFormat(Document $document)
    {
        $this->setGraphicsContextFromSourceDocumentIfNecessary($document);
    }
    
    protected function getGraphicsContextFromSourceDocument(Document $document)
    {
        $fileOfSourcePage = $this->getAttribute('document-template');
        
        if($fileOfSourcePage)
        {
            $engine = $document->loadEngine($fileOfSourcePage);
            
            $graphicsContexts = $engine->getAttachedGraphicsContexts();
            
            $count = count($graphicsContexts);
            
            if($count == 0)
            {
                return null;
            }

            $pageContext = $this->context;
            $index = ($pageContext ? ($pageContext->getPageNumber()-1) : 0) % $count;
            
            return $graphicsContexts[$index];
        }
        
        return null;
    }
    
    public function flush()
    {
        $placeholders = array('footer', 'header', 'watermark');
        foreach($placeholders as $placeholder)
        {
            if($this->$placeholder)
            {
                $this->$placeholder->flush();
                $this->$placeholder = null;
            }
        }
        
        foreach($this->runtimeNodes as $node)
        {
            $node->flush();
        }

        $this->runtimeNodes = array();

        parent::flush();
    }
}