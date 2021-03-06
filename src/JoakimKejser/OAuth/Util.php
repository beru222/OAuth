<?php
namespace JoakimKejser\OAuth;

use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class Util
{
    /**
     * URL Encodes according to RFC3986
     * @param String $input 
     * @return String
     */
    public static function urlencodeRfc3986($input)
    {
        if (is_array($input)) {
            return array_map(array('JoakimKejser\OAuth\Util', 'urlencodeRfc3986'), $input);
        } else if (is_scalar($input)) {
            return str_replace(
                '+',
                ' ',
                str_replace('%7E', '~', rawurlencode($input))
            );
        } else {
            return '';
        }
    }

    /**
     * Description
     * 
     * This decode function isn't taking into consideration the above
     * modifications to the encoding process. However, this method doesn't
     * seem to be used anywhere so leaving it as is.
     * 
     * @param String $string 
     * @return String
     */
    public static function urldecodeRfc3986($string)
    {
        return urldecode($string);
    }

    // Utility function for turning the Authorization: header into
    // parameters, has to do some unescaping
    // Can filter out any non-oauth parameters if needed (default behaviour)
    // May 28th, 2010 - method updated to tjerk.meesters for a speed improvement.
    //                  see http://code.google.com/p/oauth/issues/detail?id=163

    /**
     * Utility function for turning the Authorization: header into parameters
     * 
     * Has to do some unescaping too. Can filter out any non-oauth parameters if needed (default behaviour)
     * May 28th, 2010 - method updated to tjerk.meesters for a speed improvement.
     * see http://code.google.com/p/oauth/issues/detail?id=163
     * 
     * @param String $header Authorization Header
     * @param boolean $onlyAllowOAuthParameters 
     * @return array
     */
    public static function splitHeader($header, $onlyAllowOAuthParameters = true)
    {
        $params = array();
        if (preg_match_all('/('.($onlyAllowOAuthParameters ? 'oauth_' : '').'[a-z_-]*)=(:?"([^"]*)"|([^,]*))/', $header, $matches)) {
            foreach ($matches[1] as $i => $h) {
                $params[$h] = Util::urldecodeRfc3986(empty($matches[3][$i]) ? $matches[4][$i] : $matches[3][$i]);
            }

            if (isset($params['realm'])) {
                unset($params['realm']);
            }
        }
        return $params;
    }

    /**
     * Helper to try to sort out headers for people who aren't running apache
     * @param Symfony\Component\HttpFoundation\Request $request 
     * @return Array array of headers
     */
    public static function getHeaders(SymfonyRequest $request)
    {
        if (function_exists('apache_request_headers')) {
            // we need this to get the actual Authorization: header
            // because apache tends to tell us it doesn't exist
            $headers = apache_request_headers();

            // sanitize the output of apache_request_headers because
            // we always want the keys to be Cased-Like-This and arh()
            // returns the headers in the same case as they are in the
            // request
            $out = array();
            foreach ($headers as $key => $value) {
                $key = str_replace(
                    " ",
                    "-",
                    ucwords(strtolower(str_replace("-", " ", $key)))
                );
                $out[$key] = $value;
            }
        } else {
            // otherwise we don't have apache and are just going to have to hope
            // that $_SERVER actually contains what we need
            $out = array();

            if ( $request->server->get('CONTENT_TYPE') ) {
                $out['Content-Type'] = $request->server->get('CONTENT_TYPE');
            }

            if ( isset($_ENV['CONTENT_TYPE']) ) {
                $out['Content-Type'] = $_ENV['CONTENT_TYPE'];
            }

            // foreach ($_SERVER as $key => $value) {
            foreach ($request->server->all() as $key => $value) {
                if (substr($key, 0, 5) == "HTTP_") {
                    // this is chaos, basically it is just there to capitalize the first
                    // letter of every word that is not an initial HTTP and strip HTTP
                    // code from przemek
                        $key = str_replace(
                            " ",
                            "-",
                            ucwords(strtolower(str_replace("_", " ", substr($key, 5))))
                        );
                    $out[$key] = $value;
                }
            }
        }
        return $out;
    }

    /**
     * Parses a parameter string into an array
     * 
     * Takes a input like a=b&a=c&d=e and returns the parsed
     * parameters like this
     * array('a' => array('b','c'), 'd' => 'e')
     * 
     * @param String $input 
     * @return Array
     */
    public static function parseParameters($input)
    {
        if (!isset($input) || !$input) {
            return array();
        }

        $pairs = explode('&', $input);

        $parsedParameters = array();
        foreach ($pairs as $pair) {
            $split = explode('=', $pair, 2);
            $parameter = Util::urldecodeRfc3986($split[0]);
            $value = isset($split[1]) ? Util::urldecodeRfc3986($split[1]) : '';

            if (isset($parsedParameters[$parameter])) {
                // We have already recieved parameter(s) with this name, so add to the list
                // of parameters with this name

                if (is_scalar($parsedParameters[$parameter])) {
                    // This is the first duplicate, so transform scalar (string) into an array
                    // so we can add the duplicates
                    $parsedParameters[$parameter] = array($parsedParameters[$parameter]);
                }

                $parsedParameters[$parameter][] = $value;
            } else {
                $parsedParameters[$parameter] = $value;
            }
        }
        return $parsedParameters;
    }

    /**
     * Takes an array of parameters and turn them into a sorted query string
     * @param  Array  $params
     * @return String
     */
    public static function buildHttpQuery(Array $params)
    {
        if ( ! $params) {
            return '';
        }

        // Urlencode both keys and values
        $keys = Util::urlencodeRfc3986(array_keys($params));
        $values = Util::urlencodeRfc3986(array_values($params));
        $params = array_combine($keys, $values);

        // Parameters are sorted by name, using lexicographical byte value ordering.
        // Ref: Spec: 9.1.1 (1)
        uksort($params, 'strcmp');

        $pairs = array();
        foreach ($params as $parameter => $value) {
            if (is_array($value)) {
                // If two or more parameters share the same name, they are sorted by their value
                // Ref: Spec: 9.1.1 (1)
                // June 12th, 2010 - changed to sort because of issue 164 by hidetaka
                sort($value, SORT_STRING);
                foreach ($value as $duplicate_value) {
                    $pairs[] = $parameter . '=' . $duplicate_value;
                }
            } else {
                $pairs[] = $parameter . '=' . $value;
            }
        }
        // For each parameter, the name is separated from the corresponding value by an '=' character (ASCII code 61)
        // Each name-value pair is separated by an '&' character (ASCII code 38)
        return implode('&', $pairs);
    }
}
