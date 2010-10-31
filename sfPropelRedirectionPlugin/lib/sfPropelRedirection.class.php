<?php
/*
 * @package: sip.plugins
 * @author: Horacio Nuñez
 */

/*
 * Encapsulates all the operations and data needed to manage the use of replication for data reading operations.
 */
class sfPropelRedirection {

    static protected $_slaveConfig;
    static protected $_peerOptions;
    static protected $_started;

    /*
     * Process the replication options for the current application and seek the corresponding
     * peers to set up hooks to intercept data reading operations.
     */
    public static function init()
    {
        if (self::$_started) return;
        $redirection_conf = sfConfig::get('app_redirection_databases', null);

        if ($redirection_conf === null) return;

        //Iterate over the databases configurations.
        foreach ($redirection_conf as $dbName => $dbOptions)
        {
            //Check if there are any slaves servers configured.
            if ($dbOptions['slaves'] === null) continue;
            foreach ($dbOptions['slaves'] as &$slave)
            {
                foreach ($slave as &$param) $param = sfConfigHandler::replaceConstants($param);
                #Symfony uses 'username' but Propel expects 'user'.
                $slave['user'] = $slave['username'];
                unset($slave['username']);
            }

            self::$_slaveConfig[$dbName] = $dbOptions['slaves'];

            //Check if there is any entity that maybe be redirected to the slave.
            if ($dbOptions['entities'] === null) continue;

            //Iterate over the entities.
            foreach ($dbOptions['entities'] as $model => $options)
            {
                $peerClass = "{$model}Peer";

                //Check if the peer exits.
                if (!class_exists($peerClass)) continue;

                $doSelectStmtHook = "{$peerClass}:doSelectStmt:doSelectStmt";
                $doCountHook = "{$peerClass}:doCount:doCount";

                //Register the interceptor function on the peer hooks.
                $interceptor = array('sfPropelRedirection', 'slaveConnection');
                sfMixer::register($doSelectStmtHook, $interceptor);
                sfMixer::register($doCountHook, $interceptor);

                //Check if the peer has conditions in order to be redirected to the slave.
                if (!isset($options['conditions'])) continue;

                self::$_peerOptions[$peerClass]['conditions'] = $options['conditions'];

                //If there are zero conditions then we don't need to check a gen_column.
                if (!isset($options['gen_column'])) continue;

                $columnName = strtolower($model) . '.' . strtoupper($options['gen_column']);

                //Check if the gen column really exists in the model
                if (!in_array($columnName, $peerClass::getFieldNames(BasePeer::TYPE_COLNAME))) continue;

                self::$_peerOptions[$peerClass]['gen_column'] = $columnName;
            }
        }
        self::$_started = true;
    }

    /*
     * Given a time component array calculates the corresponding timestamp.
     * @parameter $range Mixed.
     * @return int Timestamp corresponding with the array input
     */
    public static function calcTimeStamp($range) {
        $total = 0;
        if (isset($range['s'])) $total += $range['s'];
        if (isset($range['m'])) $total += $range['m'] * 60;
        if (isset($range['h'])) $total += $range['h'] * 3600;
        if (isset($range['D'])) $total += $range['D'] * 86400;
        if (isset($range['M'])) $total += $range['M'] * 2678400; #31 days assumed.
        if (isset($range['Y'])) $total += $range['Y'] * 32140800;
        return $total;
    }

    /*
     * Enable the selection of Slave servers within the database abstraction layer.
     */
    private static function enableSlaveConf($dbName) {
        $configuration = Propel::getConfiguration();
        if (!isset($configuration['datasources'][$dbName]['slaves']['connection'])) {
            $configuration['datasources'][$dbName]['slaves']['connection'] = self::$_slaveConfig[$dbName];
            Propel::setConfiguration($configuration);
            Propel::initialize();
        }
        Propel::setForceMasterConnection(false);
    }

    /*
     * Disable the selection of Slave servers within the database abstraction layer. 
     */
    private static function disableSlaveConf($dbName) {
        Propel::setForceMasterConnection(true);
    }

    /*
     * Build a regex pattern that represents the current Symfony context
     * wich is depicted as app/module/action. The wildcar * allows to match
     * any text for a given component.
     */
    private static function getContextPattern() {
        $context = sfContext::getInstance();
        $contextInfo = array('module' => $context->getModuleName(),
            'action' => $context->getActionName());
        return "@({$contextInfo['module']}|\*)/(\*|{$contextInfo['action']})@";
    }

    public static function getConnectionToSlave($dbName) {
        self::enableSlaveConf($dbName);
        $con = Propel::getConnection($dbName, Propel::CONNECTION_READ);
        self::disableSlaveConf($dbName);
        return $con;
    }

    /*
     * Analyze the criteria and the Peer class based on a set of conditions to decide when
     * the connection object should be changed to execute on slave replication servers.
     */
    public static function slaveConnection($peerClassName, Criteria $criteria, &$con) {
        //Retrieve options for the current Peer
        $options = &self::$_peerOptions[$peerClassName];
        $ops = array(Criteria::LESS_EQUAL, Criteria::LESS_THAN, Criteria::EQUAL);
        $apply = true;

        //Check if the peer options includes conditions, if not use the slave.
        if (isset($options['conditions']) && sizeof($options['conditions']) > 0) {
            //Use slave when ANY of the conditions is satisfied.
            $apply = false;
            //Build the current context pattern to check against the ctx condition.
            $pattern = self::getContextPattern();

            //Iterate over the conditions expressions.
            foreach ($options['conditions'] as &$cond)
            {
                //Check if the ctx is given.
                if (isset($cond['ctx']) && !(preg_match($pattern, $cond['ctx']) == 1))
                    continue;

                if (isset($options['gen_column']) && isset($cond['added'])) {
                    //If it is the first time, convert the added component to be a timestamp
                    if (is_array($cond['added']))
                        $cond['added'] = self::calcTimeStamp($cond['added']);

                    $criterion = $criteria->getCriterion($options['gen_column']);
                    if ($criterion !== null && in_array($criterion->getComparison(), $ops)) {
                        $added = strtotime($criterion->getValue()) <= strtotime(date('Y-m-d')) - $cond['added'];
                        if (!$added) continue;
                    }
                }
                //Redirect to the slave, and stop iterating.
                $apply = true;
                break;
            }
        }

        if ($apply) $con = self::getConnectionToSlave($peerClassName::DATABASE_NAME);
    }
}