Feature: Site Command

  Scenario: ee executable is command working correctly
    Given 'bin/ee' is installed
    When I run 'bin/ee'
    Then STDOUT should return something like
    """
    NAME

      ee
    """

  Scenario: Check site command is present
    When I run 'bin/ee site'
    Then STDOUT should return something like
    """
    usage: ee site
    """

  Scenario: Create wp site successfully
    When I run 'bin/ee site create wp.test --type=wp'
    Then After delay of 5 seconds
      And The site 'wp.test' should have webroot
      And The site 'wp.test' should have WordPress
      And Request on 'wp.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: Create wp site successfully
    When I run 'bin/ee site create wpcache.test --type=wp --cache --proxy-cache=on'
    Then After delay of 5 seconds
    And The site 'wpcache.test' should have webroot
    And The site 'wpcache.test' should have WordPress
    And Request on 'wpcache.test' should contain following headers:
      | header                 |
      | HTTP/1.1 200 OK        |
      | X-SRCache-Fetch-Status |
      | X-Proxy-Cache          |

  Scenario: Add alias domain
    When I run 'bin/ee site update wpcache.test --add-alias-domains=alias.wpcache.test'
    And I run '/bin/bash -c 'echo "127.0.0.1 alias.wpcache.test" >> /etc/hosts''
    Then STDOUT should return something like
    """
    Success: Alias domains updated on site wpcache.test.
    """
      And After delay of 5 seconds
      And Request on 'alias.wpcache.test' should contain following headers:
        | header                 |
        | HTTP/1.1 200 OK        |
        | X-SRCache-Fetch-Status |
        | X-Proxy-Cache          |

  Scenario: Create wpsubdir site successfully
    When I run 'bin/ee site create wpsubdir.test --type=wp --mu=subdir'
      And I create subsite '1' in 'wpsubdir.test'
    Then After delay of 5 seconds
      And The site 'wpsubdir.test' should have webroot
      And The site 'wpsubdir.test' should have WordPress
      And The site 'wpsubdir.test' should be 'subdir' multisite
      And Request on 'wpsubdir.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: Create wpsubdom site successfully
    When I run 'bin/ee site create wpsubdom.test --type=wp --mu=subdom'
      And I create subsite '1' in 'wpsubdom.test'
    Then After delay of 5 seconds
      And The site 'wpsubdom.test' should have webroot
      And The site 'wpsubdom.test' should have WordPress
      And The site 'wpsubdom.test' should be 'subdomain' multisite
      And Request on 'wpsubdom.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: List the sites
    When I run 'bin/ee site list --format=text'
    Then STDOUT should return exactly
    """
    wp.test
    wpcache.test
    wpsubdir.test
    wpsubdom.test
    """

  Scenario: Delete the sites
    When I run 'bin/ee site delete wp.test --yes'
    Then STDOUT should return something like
    """
    Site wp.test deleted.
    """
      And STDERR should return exactly
      """
      """
      And The 'wp.test' db entry should be removed
      And The 'wp.test' webroot should be removed
      And Following containers of site 'wp.test' should be removed:
        | container  |
        | nginx      |
        | php        |
        | db         |
        | redis      |
        | phpmyadmin |

