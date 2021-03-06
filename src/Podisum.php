<?php

class Podisum {
    protected $mongo;

    protected $config;

    public function __construct($mongo, array $config)
    {
        $this->mongo = $mongo;
        $this->config = $config;
    }

    public function getConfig($key, $default = null)
    {
        return isset($this->config[$key]) ? $this->config[$key] : $default;
    }

    public function getConfigForTag($tag)
    {
        $cfgs = array();

        foreach($this->config['metrics'] as $cfg) {
            if ($cfg['tag'] == $tag) {
                $cfgs[] = $cfg;
            }
        }

        return $cfgs;
    }


    public function ensureIndexes()
    {
        $collection = $this->mongo->selectCollection($this->getConfig('mongo_db', 'podisum'), 'messages');
        $collection->ensureIndex(
            'cts', array('expireAfterSeconds' => $this->getConfig('default_ttl', 86400))
        );

        /*
        foreach($this->config['metrics'] as $data) {
            list($metricName, $fieldsStr) = explode('|', $data['metric']);
            $fields = explode(',', $fieldsStr);
            $metricName = str_replace('.', '_', $metricName);
            $summaries = explode(',', $data['summaries']);
            foreach ($summaries as $sm) {
                $collection = $this->mongo->selectCollection($this->getConfig('mongo_db', 'podisum'), 's'.$sm.'_' . $metricName);
            }
        }
        */
    }

    public function insertMetric($data, $metric, $ttl, $summaries)
    {
        $data['metric'] = $metric;
        $data['ttl'] = $ttl;
        $data['summaries'] = $summaries;

        $now = new \MongoDate();

        $collection = $this->mongo->selectCollection($this->getConfig('mongo_db', 'podisum'), 'messages');

        $doc = array(
            'cts' => $now,
            'data' => $data,
        );

        $collection->insert($doc, array("w" => 0));

        $summaries = explode(',', $data['summaries']);

        list($metricName, $fieldsStr) = explode('|', $data['metric']);
        $fields = explode(',', $fieldsStr);
        $metricName = str_replace('.', '_', $metricName);

        foreach ($summaries as $sm) {
            $collection = $this->mongo->selectCollection($this->getConfig('mongo_db', 'podisum'), 's'.$sm.'_' . $metricName);

            $t = time();
            $ttl = $t - $t % $sm;

            foreach ($fields as $field) {

                $fieldData = isset($data['@fields']) ? $data['@fields'] : $data;

                if (empty($field)) {
                    continue;
                }

                if (strpos($field, ';') !== false) {
                    $keys = explode(';', $field);
                    $value = $fieldData[$keys[0]][$keys[1]];
                } else {
                    $value = $fieldData[$field];
                }

                $criteria = array(
                    'field' => $value,
                    'ttl' => $ttl,
                );

                $docs = $collection->find($criteria)->count();

                if (!$docs) {
                    $collection->ensureIndex(
                        array('cts' => 1), array('expireAfterSeconds' => (int) $sm)
                    );

                    $collection->ensureIndex(
                        array(
                            'count' => -1,
                        )
                    );

                    $collection->ensureIndex(
                        array(
                            'field' => 1,
                            'ttl' => 1,
                        )
                    );

                    $values = array(
                        'cts' => $now,
                        'field' => $value,
                        'ttl' => $ttl,
                    );
                } else {
                    $values = null;
                }

                $counters = array(
                    'counter' => 1,
                );

                $docData = array(
                    '$inc' => $counters,
                );
                if ($values) {
                    $docData['$set'] = $values;
                }

                $collection->update($criteria, $docData,
                    array(
                        'upsert' => true,
                        "w" => 0,
                    ));
            }
        }
    }
}