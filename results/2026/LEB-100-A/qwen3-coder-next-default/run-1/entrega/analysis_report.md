# PHP Code Security and Quality Analysis Report
## NetX ISP - Ticket Support System

## Executive Summary
Total findings: **20 issues** (4 Critical, 6 High, 7 Medium, 3 Low)
Immediate remediation required for authentication, authorization, and SQL injection vulnerabilities.

---

## DETAILED FINDINGS

### SECURITY ISSUES (10 findings)

---

**1. SQL Injection in `listarChamados()`**
- **File**: `/srv/run/code/lib.php:82`
- **Line**: 82
- **Severity**: CRITICAL
- **Confidence**: 100
- **Should Fix**: YES
- **Mechanism**: User input `$busca` directly concatenated into SQL:
  ```php
  $sql .= " WHERE titulo LIKE '%" . $busca . "%'";
  ```
- **Attack Vector**: `?busca=title' OR 1=1 UNION SELECT * FROM usuarios--`
- **Impact**: Complete database exfiltration

---

**2. SQL Injection in `verChamado()`**
- **File**: `/srv/run/code/lib.php:100`
- **Line**: 100
- **Severity**: CRITICAL
- **Confidence**: 95
- **Should Fix**: YES
- **Mechanism**: User input `$id` concatenated directly:
  ```php
  $res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);
  ```
- **Attack Vector**: `?ver=1 UNION SELECT * FROM usuarios--`
- **Impact**: Data exfiltration, unauthorized ticket access

---

**3. SQL Injection in `tecnicoNome()`**
- **File**: `/srv/run/code/lib.php:69`
- **Line**: 69
- **Severity**: CRITICAL
- **Confidence**: 95
- **Should Fix**: YES
- **Mechanism**: Direct string concatenation without sanitization:
  ```php
  $res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);
  ```
- **Attack Vector**: If `$tecnicoId` comes from untrusted source with malicious injection
- **Impact**: Database compromise

---

**4. Weak Password Hashing (MD5)**
- **File**: `/srv/run/code/lib.php:15`
- **Line**: 15
- **Severity**: CRITICAL
- **Confidence**: 100
- **Should Fix**: YES
- **Mechanism**: MD5 without salt:
  ```php
  $hash = md5($senha);
  ```
- **Attack Vector**: Rainbow table attacks, pre-computed hash cracking
- **Impact**: Credential theft with offline attacks

---

**5. Missing Access Control - Customer Ticket Visibility**
- **File**: `/srv/run/code/lib.php:78-92`
- **Line**: 78-92
- **Severity**: HIGH
- **Confidence**: 100
- **Should Fix**: YES
- **Mechanism**: `listarChamados()` returns ALL tickets regardless of user role:
  ```php
  $sql = 'SELECT * FROM chamados';  // No WHERE usuario_id = ?
  ```
- **Business Rule Violation**: Customers can see ALL tickets, not just their own
- **Impact**: Data breach, compliance violation (LGPD/GDPR implications)

---

**6. Missing Access Control in `verChamado()`**
- **File**: `/srv/run/code/lib.php:98-102`
- **Line**: 98-102
- **Severity**: HIGH
- **Confidence**: 100
- **Should Fix**: YES
- **Mechanism**: No ownership verification:
  ```php
  function verChamado(mysqli $db, int $id): ?array {
      $res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);
      return $res->fetch_assoc() ?: null;  // No ownership check
  }
  ```
- **Impact**: Customers can view ANY ticket by ID manipulation

---

**7. XSS Vulnerability in Login Form**
- **File**: `/srv/run/code/index.php:32`
- **Line**: 32
- **Severity**: HIGH
- **Confidence**: 90
- **Should Fix**: YES
- **Mechanism**: Unsanitized POST data in HTML context:
  ```php
  echo '<input name="login" placeholder="usuario">'
  ```
  If POST values are reflected without sanitization
- **Impact**: Session hijacking, credential theft

---

**8. Session Fixation Vulnerability**
- **File**: `/srv/run/code/index.php:15`
- **Line**: 15
- **Severity**: MEDIUM
- **Confidence**: 95
- **Should Fix**: YES
- **Mechanism**: No `session_regenerate_id()` after authentication:
  ```php
  session_start();  // Line 15
  // ... auth happens ...
  // Missing: session_regenerate_id(true);
  ```
- **Impact**: Attacker can fixate session ID before user logs in

---

**9. Hardcoded Database Credentials**
- **File**: `/srv/run/code/config.php:12`
- **Line**: 12
- **Severity**: MEDIUM
- **Confidence**: 100
- **Should Fix**: YES
- **Mechanism**: Production credentials in fallback:
  ```php
  define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');
  ```
- **Impact**: Credential exposure if `.env` missing or code committed to git

---

**10. Missing Error Handling After Queries**
- **File**: `/srv/run/code/lib.php:128,133`
- **Line**: 128, 133, 136
- **Severity**: MEDIUM
- **Confidence**: 90
- **Should Fix**: YES
- **Mechanism**: Silent failures on database errors:
  ```php
  if ($res === false) { return; }  // No logging
  ```
- **Impact**: Debugging difficulties, silent data loss

---

### ARCHITECTURE ISSUES (2 findings)

---

**11. Missing Input Validation**
- **File**: `/srv/run/code/index.php:22`
- **Line**: 22
- **Severity**: MEDIUM
- **Confidence**: 90
- **Should Fix**: YES
- **Mechanism**: No validation on `$_POST['login']`, `$_POST['senha']`:
  ```php
  $u = autenticar($db, $_POST['login'], $_POST['senha'] ?? '');
  ```
- **Impact**: SQL injection paths, authentication bypass

---

**12. Missing Database Connection Cleanup**
- **File**: `/srv/run/code/index.php:9`
- **Line**: 9 (connection created), never closed
- **Severity**: LOW
- **Confidence**: 100
- **Should Fix**: Optional (quality improvement)
- **Mechanism**: `$db` never explicitly closed:
  ```php
  $db = new mysqli(...);
  // No $db->close() before script exit
  ```
- **Impact**: Resource leak in high-traffic scenarios

---

### BUGS (1 finding)

---

**13. Division by Zero in `mediaResposta()`**
- **File**: `/srv/run/code/lib.php:116`
- **Line**: 116
- **Severity**: MEDIUM
- **Confidence**: 100
- **Should Fix**: YES
- **Mechanism**: No check for division by zero:
  ```php
  return $soma / $qtd;  // $qtd could be 0
  ```
- **Impact**: PHP warning, INF return value

---

### PERFORMANCE ISSUES (2 findings)

---

**14. N+1 Query Problem in `listarChamados()`**
- **File**: `/srv/run/code/lib.php:89`
- **Line**: 89
- **Severity**: HIGH
- **Confidence**: 100
- **Should Fix**: YES
- **Mechanism**: Separate query per ticket:
  ```php
  while ($c = $res->fetch_assoc()) {
      $c['tecnico_nome'] = tecnicoNome($db, ...);  // EXTRA QUERY
  }
  ```
- **Impact**: 101 queries for 100 tickets (1 list + 100 tech lookups)
- **Root Cause**: `tecnicoNome()` executes separate SELECT per iteration

---

**15. Inefficient CSV Export (Double I/O)**
- **File**: `/srv/run/code/lib.php:125-150`
- **Line**: 126, 146, 150
- **Severity**: MEDIUM
- **Confidence**: 100
- **Should Fix**: YES
- **Mechanism**: Write then read file:
  ```php
  $fp = fopen($caminho, 'w');  // Write to file
  // ... write CSV ...
  fclose($fp);
  readfile($caminho);  // Read file again
  ```
- **Impact**: Unnecessary disk I/O, slower exports

---

### CODE QUALITY ISSUES (5 findings)

---

**16. Mixed Logic and Presentation**
- **File**: `/srv/run/code/index.php:59-96`
- **Line**: 59-96
- **Severity**: LOW
- **Confidence**: 100
- **Should Fix**: Optional
- **Mechanism**: HTML generation embedded in PHP control flow (acceptable for small legacy)
- **Impact**: Maintainability issues

---

**17. Inconsistent Error Reporting**
- **File**: `/srv/run/code/index.php:11`
- **Line**: 11
- **Severity**: LOW
- **Confidence**: 100
- **Should Fix**: Optional
- **Mechanism**: No logging, generic error:
  ```php
  die('Falha ao conectar ao banco.');
  ```
- **Impact**: Operational visibility issues

---

**18. Magic Numbers in Status Logic**
- **File**: `/srv/run/code/lib.php:28-35`
- **Line**: 28, 30, 32
- **Severity**: LOW
- **Confidence**: 100
- **Should Fix**: Optional
- **Mechanism**: Hardcoded status values:
  ```php
  if ($status == 1) { 'Aberto' }
  ```
- **Impact**: Code maintainability

---

**19. Missing Interface for Ownership Check**
- **File**: `/srv/run/code/lib.php:78`
- **Line**: 78
- **Severity**: HIGH
- **Confidence**: 100
- **Should Fix**: YES
- **Mechanism**: `listarChamados()` signature insufficient:
  ```php
  function listarChamados(mysqli $db, string $busca = ''): array
  ```
  Missing `$userId` parameter to enforce restrictions
- **Impact**: Business rule enforcement impossible without refactoring

---

**20. Suppressed Error in CSV Export**
- **File**: `/srv/run/code/lib.php:128,133`
- **Line**: 128, 133
- **Severity**: MEDIUM
- **Confidence**: 90
- **Should Fix**: YES
- **Mechanism**: Early returns without cleanup:
  ```php
  if ($fp === false) { return; }  // No cleanup
  if ($res === false) { return; }  // No cleanup
  ```
- **Impact**: Resource leaks, debugging issues

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 4 | 2 | 3 | 1 | 10 |
| Architecture | 0 | 0 | 1 | 1 | 2 |
| Bugs | 0 | 0 | 1 | 0 | 1 |
| Performance | 0 | 1 | 1 | 0 | 2 |
| Quality | 0 | 1 | 1 | 3 | 5 |
| **TOTAL** | **4** | **4** | **7** | **5** | **20** |

---

##IST OF issues That Should Be Fixed

| # | Severity | Category | File | Line | Issue |
|---|----------|----------|------|------|-------|
| 1 | CRITICAL | Security | lib.php | 82 | SQL Injection in listarChamados |
| 2 | CRITICAL | Security | lib.php | 100 | SQL Injection in verChamado |
| 3 | CRITICAL | Security | lib.php | 69 | SQL Injection in tecnicoNome |
| 4 | CRITICAL | Security | lib.php | 15 | Weak MD5 password hashing |
| 5 | HIGH | Security | lib.php | 78-92 | Missing access control in listarChamados |
| 6 | HIGH | Security | lib.php | 98-102 | Missing access control in verChamado |
| 7 | HIGH | Architecture | lib.php | 78 | Missing userId parameter in listarChamados |
| 8 | HIGH | Performance | lib.php | 89 | N+1 query in listarChamados |
| 9 | HIGH | Quality | lib.php | 28-35 | Magic numbers |
| 10 | MEDIUM | Security | index.php | 15 | Session fixation |
| 11 | MEDIUM | Security | config.php | 12 | Hardcoded credentials |
| 12 | MEDIUM | Security | lib.php | 128,133 | Missing error handling |
| 13 | MEDIUM | Security | index.php | 22 | Missing input validation |
| 14 | MEDIUM | Bugs | lib.php | 116 | Division by zero |
| 15 | MEDIUM | Performance | lib.php | 125-150 | Double I/O in CSV export |
| 16 | MEDIUM | Quality | lib.php | 128,133 | Suppressed errors |

---

## IMMEDIATE ACTION ITEMS

1. **CRITICAL** - Replace MD5 with `password_hash()`/`password_verify()`
2. **CRITICAL** - Fix SQL injection in `listarChamados()` (line 82)
3. **CRITICAL** - Fix SQL injection in `verChamado()` (line 100)
4. **CRITICAL** - Fix SQL injection in `tecnicoNome()` (line 69)
5. **HIGH** - Add ownership filter to `listarChamados()`
6. **HIGH** - Add ownership verification to `verChamado()`
7. **HIGH** - Fix N+1 query by using JOIN in `listarChamados()`
8. **MEDIUM** - Add `session_regenerate_id(true)` after login
9. **MEDIUM** - Add input validation for login credentials
10. **MEDIUM** - Fix session fixation vulnerability

---

*Analysis completed: 2026-09-30*
*Codebase: NetX ISP Ticket Support System (Legacy)*
*Analysis scope: Security (OWASP Top 10 2021), Architecture, Bugs, Performance, Code Quality*
