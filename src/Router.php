<?php

declare(strict_types=1);

namespace PHP94\Router;

use LogicException;

class Router
{
    protected $baseurl;

    protected $parser;
    protected $generator;

    protected $currentGroupPrefix = '';
    protected $currentParams = [];

    protected $staticRoutes = [];
    protected $regexToRoutesMap = [];

    const DEFAULT_DISPATCH_REGEX = '[^/]+';
    const VARIABLE_REGEX = <<<'REGEX'
\{
    \s* ([a-zA-Z_][a-zA-Z0-9_-]*) \s*
    (?:
        : \s* ([^{}]*(?:\{(?-1)\}[^{}]*)*)
    )?
\}
REGEX;

    public function __construct()
    {
        if (strpos($_SERVER['REQUEST_URI'] ?? '', $_SERVER['SCRIPT_NAME']) === 0) {
            $this->baseurl = $_SERVER['SCRIPT_NAME'];
        } else {
            $this->baseurl = strlen(dirname($_SERVER['SCRIPT_NAME'])) > 1 ? dirname($_SERVER['SCRIPT_NAME']) : '';
        }
    }

    public function setBaseUrl(string $baseurl): self
    {
        $this->baseurl = $baseurl;
        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->baseurl;
    }

    public function addGroup(string $prefix, callable $callback, array $params = []): self
    {
        $previousGroupPrefix = $this->currentGroupPrefix;
        $previousParams = $this->currentParams;
        $this->currentGroupPrefix = $previousGroupPrefix . $prefix;
        $this->currentParams = array_merge($this->currentParams, $params);
        $callback($this);
        $this->currentGroupPrefix = $previousGroupPrefix;
        $this->currentParams = $previousParams;
        return $this;
    }

    public function addRoute(
        string $route,
        string $handler,
        string $name = null,
        array $params = [],
    ): self {
        $route = $this->currentGroupPrefix . $route;
        $routeDatas = $this->parse($route);
        $params = array_merge($params, $this->currentParams);
        foreach ($routeDatas as $routeData) {
            $this->addData($routeData, $handler, $params, $name);
        }
        return $this;
    }

    public function dispatch(string $uri): ?array
    {
        list($staticRouteMap, $varRouteMap) = $this->getData();

        if (isset($staticRouteMap[$uri])) {
            return [$staticRouteMap[$uri]['handler'], $staticRouteMap[$uri]['params']];
        }

        foreach ($varRouteMap as $data) {
            if (!preg_match($data['regex'], $uri, $matches)) {
                continue;
            }
            $route = $data['routeMap'][count($matches)];
            $vars = [];
            $i = 0;
            foreach ($route['variables'] as $varName) {
                $vars[$varName] = $matches[++$i];
            }
            return [$route['handler'], array_merge($vars, $route['params'])];
        }

        return null;
    }

    public function build(string $name, array $querys = []): string
    {
        list($staticRouteMap, $variableRouteData) = $this->getData();

        $check_querys = function (array $route_querys, array $build_querys): bool {
            foreach ($route_querys as $key => $value) {
                if (isset($build_querys[$key]) && ($build_querys[$key] != $value)) {
                    return false;
                }
            }
            return true;
        };

        foreach ($staticRouteMap as $route) {
            if ($route['name'] != $name) {
                continue;
            }
            if (!$check_querys($route['params'], $querys)) {
                continue;
            }
            $querys_diff = array_diff_key($querys, $route['params']);
            if ($querys_diff) {
                return $this->getBaseUrl() . $route['routeStr'] . '?' . http_build_query($querys_diff);
            } else {
                return $this->getBaseUrl() . $route['routeStr'];
            }
        }

        $build = function (array $routeData, $params): ?array {
            $uri = '';
            foreach ($routeData as $part) {
                if (is_array($part)) {
                    if (
                        isset($params[$part[0]])
                        && preg_match('~^' . $part[1] . '$~', (string) $params[$part[0]])
                    ) {
                        $uri .= urlencode((string) $params[$part[0]]);
                        unset($params[$part[0]]);
                        continue;
                    } else {
                        return null;
                    }
                } else {
                    $uri .= $part;
                }
            }
            return [$uri, $params];
        };

        foreach ($variableRouteData as $chunk) {
            foreach ($chunk['routeMap'] as $route) {
                if ($route['name'] != $name) {
                    continue;
                }
                if (!$check_querys($route['params'], $querys)) {
                    continue;
                }
                $tmp = $build($route['routeData'], array_diff_key($querys, $route['params']));
                if (!is_array($tmp)) {
                    continue;
                }
                if ($tmp[1]) {
                    return $this->getBaseUrl() . $tmp[0] . '?' . http_build_query($tmp[1]);
                } else {
                    return $this->getBaseUrl() . $tmp[0];
                }
            }
        }

        if ($querys) {
            return $this->getBaseUrl() . $name . '?' . http_build_query($querys);
        } else {
            return $this->getBaseUrl() . $name;
        }
    }

    protected function addData(
        array $routeData,
        string $handler,
        array $params = [],
        string $name = null,
    ) {
        ksort($params);
        if ($this->isStaticRoute($routeData)) {
            $this->addStaticRoute($routeData, $handler, $params, $name);
        } else {
            $this->addVariableRoute($routeData, $handler, $params, $name);
        }
    }

    public function getData(): array
    {
        if (empty($this->regexToRoutesMap)) {
            return [$this->staticRoutes, []];
        }

        return [$this->staticRoutes, $this->generateVariableRouteData()];
    }

    protected function getApproxChunkSize(): int
    {
        return 10;
    }

    protected function processChunk(array $regexToRoutesMap): array
    {
        $routeMap = [];
        $regexes = [];
        $numGroups = 0;
        foreach ($regexToRoutesMap as $regex => $route) {
            $numVariables = count($route['variables']);
            $numGroups = max($numGroups, $numVariables);

            $regexes[] = $regex . str_repeat('()', $numGroups - $numVariables);
            $routeMap[$numGroups + 1] = $route;

            ++$numGroups;
        }

        $regex = '~^(?|' . implode('|', $regexes) . ')$~';
        return [
            'regex' => $regex,
            'routeMap' => $routeMap,
        ];
    }

    private function generateVariableRouteData(): array
    {
        $chunkSize = $this->computeChunkSize(count($this->regexToRoutesMap));
        $chunks = array_chunk($this->regexToRoutesMap, $chunkSize, true);
        return array_map([$this, 'processChunk'], $chunks);
    }

    private function computeChunkSize(int $count): int
    {
        $numParts = max(1, round($count / $this->getApproxChunkSize()));
        return (int) ceil($count / $numParts);
    }

    private function isStaticRoute(array $routeData): bool
    {
        return count($routeData) === 1 && is_string($routeData[0]);
    }

    private function addStaticRoute(
        array $routeData,
        string $handler,
        array $params = [],
        string $name = null,
    ) {
        $routeStr = $routeData[0];

        if (isset($this->staticRoutes[$routeStr])) {
            return;
        }

        foreach ($this->regexToRoutesMap as $route) {
            if (preg_match('~^' . $route['regex'] . '$~', $routeStr)) {
                throw new LogicException(sprintf(
                    'Static route "%s" is shadowed by previously defined variable route "%s"',
                    $routeStr,
                    $route['regex']
                ));
            }
        }

        $this->staticRoutes[$routeStr] = [
            'handler' => $handler,
            'params' => $params,
            'name' => $name,
            'routeStr' => $routeStr,
            'routeData' => $routeData,
        ];
    }

    private function addVariableRoute(
        array $routeData,
        string $handler,
        array $params = [],
        string $name = null,
    ) {
        list($regex, $variables) = $this->buildRegexForRoute($routeData);

        if (isset($this->regexToRoutesMap[$regex])) {
            return;
        }

        $this->regexToRoutesMap[$regex] = [
            'handler' => $handler,
            'params' => $params,
            'name' => $name,
            'regex' => $regex,
            'routeData' => $routeData,
            'variables' => $variables,
        ];
    }

    private function buildRegexForRoute(array $routeData): array
    {
        $regex = '';
        $variables = [];
        foreach ($routeData as $part) {
            if (is_string($part)) {
                $regex .= preg_quote($part, '~');
                continue;
            }

            list($varName, $regexPart) = $part;

            if (isset($variables[$varName])) {
                throw new LogicException(sprintf(
                    'Cannot use the same placeholder "%s" twice',
                    $varName
                ));
            }

            if ($this->regexHasCapturingGroups($regexPart)) {
                throw new LogicException(sprintf(
                    'Regex "%s" for parameter "%s" contains a capturing group',
                    $regexPart,
                    $varName
                ));
            }

            $variables[$varName] = $varName;
            $regex .= '(' . $regexPart . ')';
        }

        return [$regex, $variables];
    }

    private function regexHasCapturingGroups(string $regex): bool
    {
        if (false === strpos($regex, '(')) {
            return false;
        }

        return (bool) preg_match(
            '~
                (?:
                    \(\?\(
                  | \[ [^\]\\\\]* (?: \\\\ . [^\]\\\\]* )* \]
                  | \\\\ .
                ) (*SKIP)(*FAIL) |
                \(
                (?!
                    \? (?! <(?![!=]) | P< | \' )
                  | \*
                )
            ~x',
            $regex
        );
    }

    protected function parse(string $route): array
    {
        $routeWithoutClosingOptionals = rtrim($route, ']');
        $numOptionals = strlen($route) - strlen($routeWithoutClosingOptionals);

        $segments = preg_split('~' . self::VARIABLE_REGEX . '(*SKIP)(*F) | \[~x', $routeWithoutClosingOptionals);
        if ($numOptionals !== count($segments) - 1) {
            if (preg_match('~' . self::VARIABLE_REGEX . '(*SKIP)(*F) | \]~x', $routeWithoutClosingOptionals)) {
                throw new LogicException('Optional segments can only occur at the end of a route');
            }
            throw new LogicException("Number of opening '[' and closing ']' does not match");
        }

        $currentRoute = '';
        $routeDatas = [];
        foreach ($segments as $n => $segment) {
            if ($segment === '' && $n !== 0) {
                throw new LogicException('Empty optional part');
            }

            $currentRoute .= $segment;
            $routeDatas[] = $this->parsePlaceholders($currentRoute);
        }
        return $routeDatas;
    }

    private function parsePlaceholders(string $route): array
    {
        if (!preg_match_all(
            '~' . self::VARIABLE_REGEX . '~x',
            $route,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        )) {
            return [$route];
        }

        $offset = 0;
        $routeData = [];
        foreach ($matches as $set) {
            if ($set[0][1] > $offset) {
                $routeData[] = substr($route, $offset, $set[0][1] - $offset);
            }
            $routeData[] = [
                $set[1][0],
                isset($set[2]) ? trim($set[2][0]) : self::DEFAULT_DISPATCH_REGEX,
            ];
            $offset = $set[0][1] + strlen($set[0][0]);
        }

        if ($offset !== strlen($route)) {
            $routeData[] = substr($route, $offset);
        }

        return $routeData;
    }
}
