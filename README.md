# UniversalClipboard

基于网页的共享剪切板. Web-based shared clipboard.  
默认账号: user1, 默认密码: 空.  

这一剪切板具有如下特性:  
1. 基于网页, 采用轮询机制, PHP 单文件部署 (不包括 CSS);
2. 在键入完毕后的半秒内被同步到各设备;
3. 网页会产生二维码便于共享 (推荐由移动设备访问桌面设备产生的二维码);
4. 支持简单的深色模式 (二维码在深色模式下会变暗);
5. 支持简单的多账号认证和匿名. 匿名模式下使用一个不需要登录的"特殊账号". 以账号为门槛, 在 Session 模式下剪切板关联 Session ID (即根据不同的 Session ID 可支持单账号多剪切板, 但此时剪切板可跨账号访问), 在 JSON 模式下剪切板关联账号 (即意味着在匿名模式下完全共享剪切板).
6. 可设置的过期时间;
7. 通过修改 VersionKey, 可以废弃所有用户的之前剪切板;

注: 这一剪切板的设计目的是个人及小规模使用. 该工具的密码存储及传输使用未加盐 SHA1, 不建议使用重要密码. SessionName 如为 null, 则以 JSON 模式存储用户数据, 否则其字符串决定其字面意思. 过期及废弃剪切板并不意味着丢弃, 由于没有计划任务的实现, Session 模式下 PHP 本身会触发基于概率的回收, JSON 模式下只有在访问用户数据时才会检查是否过期.

## 接口
POST /clipboard.php 或 POST /clipboard.php?{SessionName}={SessionID}  
Content-Type: application/json  

首次访问/初始化  
{"version": -1, "version_hash": null, "clipboard": null}  

再次访问须使用已获得的 version 及 version_hash, 并传递客户端当前剪切板内容, 如无变化 clipboard 将为 null, 有变化即获得下一版本, 应当储存它.
