<?php
/**
 * bin/mutate's scope for the fixture plugin: every file under src/, judged by its mirror folder.
 */

return array(
	'paths'   => array( 'src' ),
	'mirrors' => array( 'src' => 'tests/unit' ),
	'threads' => 1,
	'timeout' => 60,
);
