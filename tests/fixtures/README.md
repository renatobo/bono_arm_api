# ARMember fixtures

REST integration tests deliberately avoid bundling proprietary ARMember code. CI creates the WordPress users needed for authorization and validates the plugin's dependency-unavailable behavior. A licensed ARMember installation can be supplied in an external integration environment to exercise its payment tables and deletion hooks.
