<?php

namespace Bolt\Extension\CsvExport;

use Bolt\Collection\Bag;
use Bolt\Events\CronEvent;
use Bolt\Extension\SimpleExtension;
use Bolt\Menu\MenuEntry;
use Bolt\Storage\Entity\Entity;
use Bolt\Storage\Query\Query;
use Bolt\Storage\Query\QueryResultset;
use Bolt\Storage\Repository;
use Bolt\Translation\Translator as Trans;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Csv Export extension for Bolt
 *
 * @author    Ross Riley <riley.Ross@gmail.com>
 *
 * @license   https://opensource.org/licenses/MIT MIT
 */
class Extension extends SimpleExtension
{

    protected function registerMenuEntries()
    {
        $config     = $this->getConfig();
        $prefix     = $this->getContainer()['controller.backend.mount_prefix'];
        $permission = $config['permission'] ? $config['permission'] : 'contenttype-action';

        $parent = (new MenuEntry('export'))
            ->setLabel(Trans::__('CSV Export'))
            ->setIcon('fa:file')
            ->setPermission($permission)
            ->setGroup(true);

        foreach ($this->getAvailableExports() as $key => $export) {
            $parent->add(
                (new MenuEntry('export ' . $key, $prefix . '/export/' . $key))
                    ->setLabel('Export ' . $export['name'])
                    ->setIcon('fa:file')
            );
        }

        return [$parent];
    }

    /**
     * {@inheritdoc}
     */
    protected function registerBackendRoutes(ControllerCollection $collection)
    {
        $collection->get('/export/{contenttype}', [$this, 'doExport']);
    }

    /**
     * @param string         $ct
     * @param QueryResultset $records
     *
     * @return array
     */
    private function fetchingRecords($ct, QueryResultset $records)
    {
        $config     = $this->getConfig();
        $outputData = [];
        foreach ($records as $record) {
            $compiled = [];
            $record   = $this->processRecord($ct, $record);
            foreach ($record as $fieldName => $field) {
                $outputKey = isset($config['mappings'][$ct][$fieldName]) ? $config['mappings'][$ct][$fieldName] : $fieldName;

                if ($outputKey === false) {
                    continue;
                }

                $outputVal = $this->serializeField($field);

                if (is_array($outputKey)) {
                    # Check for formatted values
                    if (array_key_exists('values', $outputKey)) {
                        # Check if the answer is a json (multiple choice, for select field in content type)
                        $decodedOutput = json_decode($outputVal, true);
                        if (is_array($decodedOutput)) {
                            $multiple = [];
                            foreach ($decodedOutput as $decoded) {
                                if (array_key_exists($decoded, $outputKey['values'])) {
                                    $multiple[] = $outputKey['values'][$decoded];
                                }
                            }
                            if (!empty($multiple)) {
                                $outputVal = implode("\n", $multiple);
                            }
                        } elseif (array_key_exists($outputVal, $outputKey['values'])) {
                            $outputVal = $outputKey['values'][$outputVal];
                        }

                    }
                    # Then check for field title
                    $outputKey = isset($outputKey['title']) ? $outputKey['title'] : $fieldName;
                }

                $compiled[$outputKey] = $outputVal;
            }
            $outputData[] = $compiled;
        }

        $csvData = [];
        if (count($outputData)) {
            $headers   = array_keys($outputData[0]);
            $csvData[] = $headers;
        }

        foreach ($outputData as $csvRow) {
            $csvData[] = array_values($csvRow);
        }

        return $csvData;
    }

    /**
     * @param string    $ct
     * @param CronEvent $event
     * @param null      $dateStart
     *
     * @return CsvResponse
     */
    public function doCronExport($ct, CronEvent $event, $dateStart = null)
    {
        // We shouldn't be able to get here with an invalid CT but if we do, just use an empty array
        if (!$this->canExport($ct)) {
            return new CsvResponse([]);
        }

        $params = [];
        if ($dateStart !== null) {
            $params['datecreated'] = $dateStart;
        }
        $records = $this->getRecords($ct, $params);

        $event->output->writeln('Found ' . $records->count() . ' records to export');

        return $this->export($ct, $records);
    }

    /**
     * @param string                                      $contentType
     * @param \Bolt\Storage\Entity\Content|QueryResultset $records
     *
     * @return CsvResponse
     */
    private function export($contentType, $records)
    {
        $csvData = $this->fetchingRecords($contentType, $records);

        $filename  = $this->getFileName($contentType);
        $separator = $this->getSeparator();

        return new CsvResponse($filename, $csvData, $separator);
    }

    /**
     * @param string $ct
     * @param array  $params
     *
     * @return \Bolt\Storage\Entity\Content|QueryResultset|null
     */
    private function getRecords($ct, $params = [])
    {
        /** @var Query $query */
        $query = $this->getContainer()['query'];

        /** @var QueryResultset $records */
        return $query->getContent($ct, $params);
    }

    /**
     * @param Request $request
     *
     * @return CsvResponse
     */
    public function doExport(Request $request)
    {
        $ct = $request->get('contenttype');

        // We shouldn't be able to get here with an invalid CT but if we do, just use an empty array
        if (!$this->canExport($ct)) {
            return new CsvResponse([]);
        }

        /** @var QueryResultset $records */
        $records = $this->getRecords($ct);

        return $this->export($ct, $records);
    }

    /**
     * @return string
     */
    private function getSeparator()
    {
        $config = $this->getConfig();

        return isset($config['separator']) ? $config['separator'] : ',';
    }

    /**
     * @param string $ct
     *
     * @return mixed
     */
    private function getFileName($ct)
    {
        $config = $this->getConfig();

        return isset($config['file_names'][$ct]) ? $config['file_names'][$ct] : $ct;
    }

    /**
     * Method that can be called recursively to handle flattening field values
     *
     * @param $field
     *
     * @return string
     */
    public function serializeField($field)
    {
        $output = '';
        if (is_array($field)) {
            foreach ($field as $item) {
                $output .= $this->serializeField($item) . ',';
            }
        } else {
            $output .= $field . ',';
        }

        return rtrim($output, ',');
    }

    protected function processRecord($contentType, $record)
    {
        $app = $this->getContainer();
        /** @var Repository $repo */
        $repo     = $app['storage']->getRepository($contentType);
        $metadata = $repo->getClassMetadata();
        $values   = [];

        foreach ($metadata->getFieldMappings() as $field) {
            $fieldName = $field['fieldname'];
            $val       = $record->$fieldName;
            if (in_array($field['type'], ['date', 'datetime'])) {
                $val = (string)$record->$fieldName;
            }
            if (is_callable([$val, 'serialize'])) {
                /** @var Entity $val */
                $val = $val->serialize();
            }
            $values[$fieldName] = $val;
        }

        return $values;
    }

    /**
     * @return Bag
     */
    protected function getAvailableExports()
    {
        $app          = $this->getContainer();
        $config       = $this->getConfig();
        $contentTypes = Bag::from($app['config']->get('contenttypes'));

        $exports = $contentTypes->filter(function ($key, $item) use ($config) {
            if (!is_array($config['disabled'])) {
                return true;
            }
            if (!in_array($key, $config['disabled'], true)) {
                return true;
            }
        });

        return $exports;
    }

    protected function canExport($ct)
    {
        $exports = $this->getAvailableExports();

        return $exports->has($ct);
    }

}
