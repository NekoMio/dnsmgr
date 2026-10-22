<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use Exception;

/**
 * anyns.io DNS 驱动
 *
 * 使用管理 API（/api/dns/v1），需要 manage 类型令牌（nhtdns_ 前缀）。
 * 文档：https://anyns.io/docs/api
 *
 * 说明（已对真实接口实测）：
 * - 列表接口不支持分页/筛选，统一在本地完成，故 DnsHelper 中 page=true；
 * - 记录支持 remark 字段（文档未写，实测可用），log/weight 不支持；
 * - 更新记录时未传的 ttl/line/enabled/remark 会保留原值；
 * - MX 优先级没有独立字段，必须拼进 value（"10 mail.example.com"），否则报 service_error；
 * - TTL 最小 60，低于 60 会被自动修正为 60。
 */
class anyns implements DnsInterface
{
    private $token;
    private $baseUrl;
    private $error;
    private $errorCode;
    private $domain;
    private $domainid;
    private $proxy;

    function __construct($config)
    {
        $this->token = trim($config['token']);
        $baseUrl = !empty($config['base_url']) ? $config['base_url'] : 'https://anyns.io';
        $this->baseUrl = rtrim($baseUrl, '/') . '/api/dns/v1';
        $this->domain = rtrim(strval($config['domain']), '.');
        $this->domainid = $config['domainid'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
    }

    public function getError()
    {
        return $this->error;
    }

    public function check()
    {
        return $this->getDomainList() !== false;
    }

    //获取域名列表（接口一次性返回全部，本地筛选+分页）
    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 20)
    {
        $data = $this->send_request('GET', '/zones');
        if ($data === false) return false;

        $rows = $this->pickList($data, ['zones', 'list', 'items', 'rows']);
        if ($rows === false) {
            $this->setError('获取域名列表失败：返回数据格式异常');
            return false;
        }

        $list = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) continue;
            $name = $this->pickValue($row, ['zone_name', 'name', 'domain']);
            if ($name === null || $name === '') continue;
            $name = rtrim($name, '.');
            if (!isNullOrEmpty($KeyWord) && stripos($name, $KeyWord) === false) continue;
            $list[] = [
                'DomainId' => strval($row['id']),
                'Domain' => $name,
                'RecordCount' => intval($this->pickValue($row, ['record_count', 'records_count', 'count'], 0)),
            ];
        }

        $total = count($list);
        $offset = max(0, ($PageNumber - 1) * $PageSize);
        return ['total' => $total, 'list' => array_slice($list, $offset, $PageSize)];
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $rows = $this->getRecordsRaw();
        if ($rows === false) return false;

        $lineMap = $this->getRecordLine();
        $list = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) continue;
            $record = $this->formatRecord($row, $lineMap);
            if ($record === false) continue;
            $list[] = $record;
        }

        if (!isNullOrEmpty($SubDomain)) {
            $list = array_values(array_filter($list, function ($v) use ($SubDomain) {
                return strcasecmp($v['Name'], $SubDomain) === 0;
            }));
        } else {
            if (!isNullOrEmpty($KeyWord)) {
                $list = array_values(array_filter($list, function ($v) use ($KeyWord) {
                    return stripos($v['Name'], $KeyWord) !== false || stripos(strval($v['Value']), $KeyWord) !== false;
                }));
            }
            if (!isNullOrEmpty($Value)) {
                $list = array_values(array_filter($list, function ($v) use ($Value) {
                    return strcasecmp(strval($v['Value']), $Value) === 0;
                }));
            }
            if (!isNullOrEmpty($Type)) {
                $list = array_values(array_filter($list, function ($v) use ($Type) {
                    return strcasecmp($v['Type'], $Type) === 0;
                }));
            }
            if (!isNullOrEmpty($Line)) {
                $list = array_values(array_filter($list, function ($v) use ($Line) {
                    return strval($v['Line']) === strval($Line);
                }));
            }
            if (!isNullOrEmpty($Status)) {
                $list = array_values(array_filter($list, function ($v) use ($Status) {
                    return strval($v['Status']) === strval($Status);
                }));
            }
        }

        $total = count($list);
        $offset = max(0, ($PageNumber - 1) * $PageSize);
        return ['total' => $total, 'list' => array_slice($list, $offset, $PageSize)];
    }

    //获取子域名解析记录列表
    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = null, $Line = null)
    {
        if ($SubDomain === '') $SubDomain = '@';
        return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain, null, $Type, $Line);
    }

    //获取解析记录详细信息（接口无单条查询，从列表中匹配）
    public function getDomainRecordInfo($RecordId)
    {
        $rows = $this->getRecordsRaw();
        if ($rows === false) return false;

        $lineMap = $this->getRecordLine();
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) continue;
            if (strval($row['id']) !== strval($RecordId)) continue;
            return $this->formatRecord($row, $lineMap);
        }
        $this->setError('解析记录不存在');
        return false;
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $param = $this->recordParam($Name, $Type, $Value, $Line, $TTL, $MX, $Remark);
        $data = $this->send_request('POST', '/zones/' . rawurlencode($this->domainid) . '/records', $param);
        if ($data === false) {
            // 完全相同的记录已存在时视为成功，便于批量/同步场景幂等
            if ($this->errorCode == 'record_duplicate') {
                $this->error = null;
                $this->errorCode = null;
                return true;
            }
            return false;
        }
        $record = $this->pickValue($this->pickObject($data, ['data']), ['record']);
        if (is_array($record) && isset($record['id'])) return strval($record['id']);
        return true;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $param = $this->recordParam($Name, $Type, $Value, $Line, $TTL, $MX, $Remark);
        $param['id'] = intval($RecordId);
        $data = $this->send_request('POST', '/zones/' . rawurlencode($this->domainid) . '/records', $param);
        return $data !== false;
    }

    //修改解析记录备注
    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        $info = $this->getDomainRecordInfo($RecordId);
        if (!$info) return false;
        $param = $this->recordParam($info['Name'], $info['Type'], $info['Value'], $info['Line'], $info['TTL'], $info['MX'], $Remark);
        $param['id'] = intval($RecordId);
        $data = $this->send_request('POST', '/zones/' . rawurlencode($this->domainid) . '/records', $param);
        return $data !== false;
    }

    //删除解析记录
    public function deleteDomainRecord($RecordId)
    {
        $data = $this->send_request('DELETE', '/zones/' . rawurlencode($this->domainid) . '/records/' . rawurlencode($RecordId));
        return $data !== false;
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        $info = $this->getDomainRecordInfo($RecordId);
        if (!$info) return false;
        $param = $this->recordParam($info['Name'], $info['Type'], $info['Value'], $info['Line'], $info['TTL'], $info['MX']);
        $param['id'] = intval($RecordId);
        $param['enabled'] = $Status == '1';
        $data = $this->send_request('POST', '/zones/' . rawurlencode($this->domainid) . '/records', $param);
        return $data !== false;
    }

    //获取解析记录操作日志（anyns 不支持）
    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        return false;
    }

    //获取解析线路列表
    public function getRecordLine()
    {
        $cacheKey = 'anyns_lines_' . md5($this->baseUrl . '|' . $this->token);
        $cached = cache($cacheKey);
        if (!empty($cached) && is_array($cached)) return $cached;

        $list = [];
        $data = $this->send_request('GET', '/lines');
        if ($data !== false) {
            $rows = $this->pickList($data, ['lines', 'list', 'items', 'rows']);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $code = $this->pickValue($row, ['code', 'line', 'id']);
                    if ($code === null || $code === '') continue;
                    $list[strval($code)] = [
                        'name' => strval($this->pickValue($row, ['name', 'label'], $code)),
                        'parent' => $this->pickValue($row, ['parent']),
                    ];
                }
            }
        }

        if (empty($list)) {
            // 接口异常时退回默认线路，避免整个记录页无法打开
            return ['default' => ['name' => '默认', 'parent' => null]];
        }
        if (!isset($list['default'])) {
            $list = ['default' => ['name' => '默认', 'parent' => null]] + $list;
        }
        cache($cacheKey, $list, 86400);
        return $list;
    }

    //获取域名最低TTL（实测：低于 60 会被服务端修正为 60）
    public function getMinTTL()
    {
        return 60;
    }

    public function addDomain($Domain)
    {
        $data = $this->send_request('POST', '/zones', ['zone_name' => $Domain]);
        if ($data === false) return false;

        $zone = $this->pickObject($data, ['zone', 'data']);
        if (!is_array($zone) || !isset($zone['id'])) {
            $this->setError('添加域名失败：返回数据格式异常');
            return false;
        }
        $name = $this->pickValue($zone, ['zone_name', 'name'], $Domain);
        return ['id' => strval($zone['id']), 'name' => rtrim($name, '.')];
    }

    /**
     * 组装写入用的记录参数
     */
    private function recordParam($Name, $Type, $Value, $Line, $TTL, $MX = 1, $Remark = null)
    {
        if ($Type == 'MX' && !isNullOrEmpty($MX) && !preg_match('/^\d+\s+/', $Value)) {
            $Value = intval($MX) . ' ' . $Value;
        }
        $param = [
            'name' => $Name === '' ? '@' : $Name,
            'type' => $Type,
            'value' => strval($Value),
            'ttl' => intval($TTL),
        ];
        if (!isNullOrEmpty($Line)) $param['line'] = strval($Line);
        // remark 传 null 表示保持原值，传空字符串表示清空
        if ($Remark !== null) $param['remark'] = strval($Remark);
        return $param;
    }

    /**
     * 统一格式化记录；MX 的优先级从 value 中拆出
     */
    private function formatRecord($row, $lineMap = [])
    {
        $type = isset($row['type']) ? strtoupper($row['type']) : '';
        if ($type === '') return false;

        $name = isset($row['name']) ? rtrim(strval($row['name']), '.') : '@';
        if ($name === '') $name = '@';
        if ($name !== '@' && $name === $this->domain) {
            $name = '@';
        } elseif ($name !== '@' && str_ends_with($name, '.' . $this->domain)) {
            $name = substr($name, 0, -(strlen($this->domain) + 1));
        }

        $value = isset($row['value']) ? strval($row['value']) : '';
        $mx = null;
        if ($type == 'MX') {
            if (preg_match('/^(\d+)\s+(.+)$/', $value, $m)) {
                $mx = $m[1];
                $value = rtrim($m[2], '.');
            } elseif (isset($row['priority'])) {
                $mx = strval($row['priority']);
            }
        }

        $line = isset($row['line']) && $row['line'] !== '' ? strval($row['line']) : 'default';
        $status = $this->isEnabled($row) ? '1' : '0';

        return [
            'RecordId' => strval($row['id']),
            'Domain' => $this->domain,
            'Name' => $name,
            'Type' => $type,
            'Value' => $value,
            'Line' => $line,
            'LineName' => isset($lineMap[$line]) ? $lineMap[$line]['name'] : $line,
            'TTL' => isset($row['ttl']) ? intval($row['ttl']) : null,
            'MX' => $mx,
            'Status' => $status,
            'Weight' => null,
            'Remark' => isset($row['remark']) && $row['remark'] !== '' ? strval($row['remark']) : null,
            'UpdateTime' => $this->pickValue($row, ['updated_at', 'update_time', 'modified_at']),
        ];
    }

    private function isEnabled($row)
    {
        $value = $this->pickValue($row, ['enabled', 'status', 'active'], true);
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value == 1;
        return in_array(strtolower(strval($value)), ['1', 'true', 'yes', 'on', 'enabled', 'active'], true);
    }

    private function getRecordsRaw()
    {
        $data = $this->send_request('GET', '/zones/' . rawurlencode($this->domainid) . '/records');
        if ($data === false) return false;

        $rows = $this->pickList($data, ['records', 'list', 'items', 'rows']);
        if ($rows === false) {
            $this->setError('获取解析记录失败：返回数据格式异常');
            return false;
        }
        return $rows;
    }

    /**
     * 从响应中取列表：兼容 data 为数组或 {zones:[]}/{records:[]} 等结构
     */
    private function pickList($data, $keys = [])
    {
        $data = $this->pickObject($data, ['data']);
        if (!is_array($data)) return false;
        if (array_is_list($data)) return $data;
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $value = $data[$key];
                return array_is_list($value) ? $value : array_values($value);
            }
        }
        return false;
    }

    /**
     * 从响应中取对象：优先 data.xxx，其次 data 本身
     */
    private function pickObject($data, $keys = [])
    {
        if (!is_array($data)) return $data;
        if (isset($data['data']) && is_array($data['data'])) $data = $data['data'];
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) return $data[$key];
        }
        return $data;
    }

    private function pickValue($data, $keys, $default = null)
    {
        if (!is_array($data)) return $default;
        foreach ($keys as $key) {
            if (isset($data[$key])) return $data[$key];
        }
        return $default;
    }

    private function send_request($method, $path, $params = null, $retry = 2)
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];

        $body = null;
        if ($method == 'GET' || $method == 'DELETE') {
            if (!empty($params)) $url .= '?' . http_build_query($params);
        } else {
            $body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers['Content-Type'] = 'application/json';
        }

        $this->error = null;
        $this->errorCode = null;
        try {
            $response = http_request($url, $body, null, null, $headers, $this->proxy, $method, 20);
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }

        // 触发限流时按 Retry-After 退避重试
        if ($response['code'] == 429 && $retry > 0) {
            $wait = 2;
            foreach ($response['headers'] as $name => $values) {
                if (strcasecmp($name, 'Retry-After') === 0 && !empty($values[0]) && ctype_digit(strval($values[0]))) {
                    $wait = min(5, max(1, intval($values[0])));
                    break;
                }
            }
            sleep($wait);
            return $this->send_request($method, $path, $params, $retry - 1);
        }

        $arr = json_decode($response['body'], true);

        if ($response['code'] >= 200 && $response['code'] < 300) {
            if (!is_array($arr)) return true;
            if (isset($arr['error'])) {
                $this->errorCode = $arr['error'];
                $this->setError(isset($arr['message']) ? $arr['message'] : $arr['error']);
                return false;
            }
            return $arr;
        }

        if (is_array($arr) && isset($arr['error'])) {
            $this->errorCode = $arr['error'];
            $this->setError(isset($arr['message']) ? $arr['message'] : $arr['error']);
        } else {
            $this->setError('HTTP ' . $response['code'] . '：' . mb_substr($response['body'], 0, 200));
        }
        return false;
    }

    private function setError($message)
    {
        $this->error = $message;
    }
}
