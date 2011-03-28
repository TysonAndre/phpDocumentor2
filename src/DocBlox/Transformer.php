<?php
/**
 * DocBlox
 *
 * @category   DocBlox
 * @package    Parser
 * @copyright  Copyright (c) 2010-2011 Mike van Riel / Naenius. (http://www.naenius.com)
 */

/**
 * Core class responsible for transforming the structure.xml file to a set of artifacts.
 *
 * @category   DocBlox
 * @package    Parser
 * @author     Mike van Riel <mike.vanriel@naenius.com>
 */
class DocBlox_Transformer extends DocBlox_Core_Abstract
{
  /** @var string|null Target location where to output the artifacts */
  protected $target = null;

  /** @var DOMDocument|null DOM of the structure as generated by the parser. */
  protected $source = null;

  /** @var string[] */
  protected $templates = array();

  /** @var DocBlox_Transformer_Transformation[] */
  protected $transformations = array();

  /**
   * Initialize the transformations.
   */
  public function __construct()
  {
    $this->loadTransformations();
  }

  /**
   * Sets the target location where to output the artifacts.
   *
   * @throws Exception if the target is not a valid writable directory.
   *
   * @param string $target The target location where to output the artifacts.
   *
   * @return void
   */
  public function setTarget($target)
  {
    $path = realpath($target);
    if (!file_exists($path) && !is_dir($path) && !is_writable($path))
    {
      throw new Exception('Given target directory (' . $target . ') does not exist or is not writable');
    }

    $this->target = $path;
  }

  /**
   * Returns the location where to store the artifacts.
   *
   * @return string
   */
  public function getTarget()
  {
    return $this->target;
  }

  /**
   * Sets the location of the structure file.
   *
   * @throws Exception if the source is not a valid readable file.
   *
   * @param string $source The location of the structure file as full path (may be relative).
   *
   * @return void
   */
  public function setSource($source)
  {
    $path = realpath($source);
    if (!file_exists($path) || !is_readable($path) || !is_file($path))
    {
      throw new Exception('Given source (' . $source . ') does not exist or is not readable');
    }

    // convert to dom document so that the writers do not need to
    $xml = new DOMDocument();
    $xml->load($path);

    $this->addMetaDataToStructure($xml);

    $this->source = $xml;
  }

  /**
   * Returns the source Structure.
   *
   * @return null|DOMDocument
   */
  public function getSource()
  {
    return $this->source;
  }

  /**
   * Sets one or more templates as basis for the transformations.
   *
   * @param string|string[] $template
   *
   * @return void
   */
  public function setTemplates($template)
  {
    // reset
    $this->templates = array();
    $this->transformations = array();

    if (!is_array($template))
    {
      $template = array($template);
    }

    foreach($template as $item)
    {
      $this->addTemplate($item);
    }
  }

  /**
   * Returns the list of templates which are going to be adopted.
   *
   * @return string[]
   */
  public function getTemplates()
  {
    return $this->templates;
  }

  /**
   * Loads the transformation from the configuration and from the given templates and/or transformations.
   *
   * @param string[] $templates                       Array of template names.
   * @param Transformation[]|array[] $transformations Array of transformations or arrays representing transformations.
   *
   * @see self::addTransformation() for more details regarding the array structure.
   *
   * @return void
   */
  public function loadTransformations(array $templates = array(), array $transformations = array())
  {
    /** @var Zend_Config_Xml[] $config_transformations */
    $config_transformations = $this->getConfig()->get('transformations', array());

    foreach($config_transformations as $transformation)
    {
      // if a writer is defined then it is a template; otherwise it is a template
      if (isset($transformation->writer))
      {
        $this->addTransformation($transformation->toArray());
        continue;
      }

      $this->addTemplate($transformation->name);
    }

    array_walk($templates, array($this, 'addTemplate'));
    array_walk($transformations, array($this, 'addTransformation'));
  }

  /**
   * Loads a template by name, if an additional array with details is provided it will try to load parameters from it.
   *
   * @param string        $name
   * @param string[]|null $details
   *
   * @return void
   */
  public function addTemplate($name)
  {
    // if the template is already loaded we do not reload it.
    if (in_array($name, $this->getTemplates()))
    {
      return;
    }

    $config = $this->getConfig();
    if (!isset($config->templates->$name))
    {
      throw new InvalidArgumentException('Template "' . $name . '" could not be found');
    }

    // track templates to be able to refer to them later
    $this->templates[] = $name;

    // template does not have transformations; return
    if (!isset($config->templates->$name->transformations))
    {
      return;
    }

    $transformations = $config->templates->$name->transformations->transformation->toArray();

    // if the array key is not numeric; then there is a single value instead of an array of transformations
    $transformations = (is_numeric(key($transformations)))
      ? $transformations
      : array($transformations);

    foreach($transformations as $transformation)
    {
      $this->addTransformation($transformation);
    }
  }

  /**
   * Adds the given transformation to the transformer for execution.
   *
   * It is also allowed to pass an array notation for the transformation; then this method will create
   * a transformation object out of it.
   *
   * The structure for this array must be:
   * array(
   *   'query'        => <query>,
   *   'writer'       => <writer>,
   *   'source'       => <source>,
   *   'artifact'     => <artifact>,
   *   'parameters'   => array(<parameters>),
   *   'dependencies' => array(<dependencies>)
   * )
   *
   * @param Transformation|array $transformation
   *
   * @return void
   */
  public function addTransformation($transformation)
  {
    if (is_array($transformation))
    {
      // check if all required items are present
      if (!key_exists('query', $transformation)
        || !key_exists('writer', $transformation)
        || !key_exists('source', $transformation)
        || !key_exists('artifact', $transformation))
      {
        throw new InvalidArgumentException(
          'Transformation array is missing elements, received: ' . var_export($transformation, true)
        );
      }

      $transformation_obj = new DocBlox_Transformer_Transformation(
        $this,
        $transformation['query'],
        $transformation['writer'],
        $transformation['source'],
        $transformation['artifact']
      );
      if (isset($transformation['parameters']) && is_array($transformation['parameters']))
      {
        $transformation_obj->setParameters($transformation['parameters']);
      }

      $transformation = $transformation_obj;
    }

    // if it is still not an object; fail
    if (!is_object($transformation))
    {
      throw new InvalidArgumentException(
        'Only transformations of type (or descended from) DocBlox_Transformer_Transformation can be used in the '
          . 'transformation process; received: ' . gettype($transformation)
      );
    }

    // if the object is not a DocBlox_Transformer_Transformation; we cannot use it
    if (!$transformation instanceof DocBlox_Transformer_Transformation)
    {
      throw new InvalidArgumentException(
        'Only transformations of type (or descended from) DocBlox_Transformer_Transformation can be used in the '
          . 'transformation process; received: '.get_class($transformation)
      );
    }

    $this->transformations[] = $transformation;
  }

  /**
   * Returns the transformation which this transformer will process.
   *
   * @return DocBlox_Transformer_Transformation[]
   */
  public function getTransformations()
  {
    return $this->transformations;
  }

  /**
   * Executes each transformation.
   *
   * @return void
   */
  public function execute()
  {
    foreach($this->getTransformations() as $transformation)
    {
      $this->log('Applying transformation query ' . $transformation->getQuery()
        . ' using writer '. get_class($transformation->getWriter()));

      $transformation->execute($this->getSource());
    }
  }

  /**
   * Adds extra information to the structure.
   *
   * This method enhances the Structure information with the following information:
   * - Every file receives a 'generated-path' attribute which contains the path on the filesystem where the docs for
   *   that file van be found.
   * - Every @see tag, or a tag with a type receives an attribute with a direct link to that tag's type entry.
   * - Every tag receives an excerpt containing the first 15 characters.
   *
   * @param DOMDocument $xml
   *
   * @return void
   */
  protected function addMetaDataToStructure(DOMDocument &$xml)
  {
    $xpath = new DOMXPath($xml);

    // find all files and add a generated-path variable
    $this->log('Adding path information to each xml "file" tag');
    $qry = $xpath->query("/project/file[@path]");

    /** @var DOMElement $element */
    foreach ($qry as $element)
    {
      $files[] = $element->getAttribute('path');
      $element->setAttribute('generated-path', $this->generateFilename($element->getAttribute('path')));
    }

    // add to classes
    $qry = $xpath->query('//class[full_name]/..');
    $class_paths = array();

    /** @var DOMElement $element */
    foreach ($qry as $element)
    {
      $path = $element->getAttribute('path');
      foreach ($element->getElementsByTagName('class') as $class)
      {
        $class_paths[$class->getElementsByTagName('full_name')->item(0)->nodeValue] = $path;
      }
    }

    // add to interfaces
    $qry = $xpath->query('//interface[full_name]/..');
    /** @var DOMElement $element */
    foreach ($qry as $element)
    {
      $path = $element->getAttribute('path');

      /** @var DOMElement $class */
      foreach ($element->getElementsByTagName('interface') as $class)
      {
        $class_paths[$class->getElementsByTagName('full_name')->item(0)->nodeValue] = $path;
      }
    }

    // add extra xml elements to tags
    $this->log('Adding link information and excerpts to all DocBlock tags');
    $qry = $xpath->query('//docblock/tag/@type|//docblock/tag/type|//extends|//implements');

    /** @var DOMElement $element */
    foreach ($qry as $element)
    {
      $type = rtrim($element->nodeValue, '[]');
      $node = ($element->nodeType == XML_ATTRIBUTE_NODE)
        ? $element->parentNode
        : $element;

      if (isset($class_paths[$type]))
      {
        $file_name = $this->generateFilename($class_paths[$type]);
        $node->setAttribute('link', $file_name . '#' . $type);
      }

      // add a 15 character excerpt of the node contents, meant for the sidebar
      $node->setAttribute('excerpt', utf8_encode(substr($type, 0, 15) . (strlen($type) > 15 ? '...' : '')));
    }


    $qry = $xpath->query('//docblock/tag[@name="see" or @name="throw" or @name="throws"]');
    /** @var DOMElement $element */
    foreach ($qry as $element)
    {
      $node_value = explode('::', $element->nodeValue);
      if (isset($class_paths[$node_value[0]]))
      {
        $file_name = $this->generateFilename($class_paths[$node_value[0]]);
        $element->setAttribute('link', $file_name . '#' . $element->nodeValue);
      }
    }
  }

  /**
   * Converts a source file name to the name used for generating the end result.
   *
   * @param string $file
   *
   * @return string
   */
  public function generateFilename($file)
  {
    $info = pathinfo(str_replace(DIRECTORY_SEPARATOR, '_', trim($file, DIRECTORY_SEPARATOR . '.')));
    return $info['filename'].'.html';
  }
}