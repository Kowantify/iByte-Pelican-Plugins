package restic_test

import (
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/pelican/wings/config"
	"github.com/pelican/wings/router"
	"github.com/pelican/wings/server"
)

// Exercises the real patched router and upstream middleware, not a look-alike auth handler.
func TestPatchedRouterInheritsDaemonAuthAndServerIsolation(t *testing.T) {
	config.Set(&config.Configuration{AuthenticationToken: "disposable-token", AuthenticationTokenId: "test"})
	manager := server.NewEmptyManager(nil)
	s, err := server.New(nil)
	if err != nil {
		t.Fatal(err)
	}
	s.Config().Uuid = "11111111-1111-4111-8111-111111111111"
	manager.Add(s)
	routes := router.Configure(manager, nil)
	for _, test := range []struct {
		token, path string
		status      int
	}{
		{"", "/api/servers/11111111-1111-4111-8111-111111111111/restic/readiness", 401},
		{"Bearer wrong", "/api/servers/11111111-1111-4111-8111-111111111111/restic/readiness", 403},
		{"Bearer disposable-token", "/api/servers/22222222-2222-4222-8222-222222222222/restic/readiness", 404},
		{"", "/api/servers/11111111-1111-4111-8111-111111111111/restic/recovery", 401},
		{"Bearer wrong", "/api/servers/11111111-1111-4111-8111-111111111111/restic/recovery", 403},
		{"Bearer disposable-token", "/api/servers/22222222-2222-4222-8222-222222222222/restic/recovery", 404},
		{"", "/api/servers/11111111-1111-4111-8111-111111111111/restic/server-key", 401},
		{"Bearer wrong", "/api/servers/11111111-1111-4111-8111-111111111111/restic/server-key", 403},
		{"Bearer disposable-token", "/api/servers/22222222-2222-4222-8222-222222222222/restic/server-key", 404},
		{"", "/api/servers/11111111-1111-4111-8111-111111111111/restic/repository-plan", 401},
		{"Bearer wrong", "/api/servers/11111111-1111-4111-8111-111111111111/restic/repository-plan", 403},
		{"Bearer disposable-token", "/api/servers/22222222-2222-4222-8222-222222222222/restic/repository-plan", 404},
		{"", "/api/servers/11111111-1111-4111-8111-111111111111/restic/repository-state", 401},
		{"Bearer wrong", "/api/servers/11111111-1111-4111-8111-111111111111/restic/repository-state", 403},
		{"Bearer disposable-token", "/api/servers/22222222-2222-4222-8222-222222222222/restic/repository-state", 404},
		{"", "/api/servers/11111111-1111-4111-8111-111111111111/restic/jobs", 401},
		{"Bearer wrong", "/api/servers/11111111-1111-4111-8111-111111111111/restic/jobs", 403},
		{"Bearer disposable-token", "/api/servers/22222222-2222-4222-8222-222222222222/restic/jobs", 404},
		{"", "/api/restic/repository-delete", 401},
		{"Bearer wrong", "/api/restic/repository-delete", 403},
		{"Bearer disposable-token", "/api/restic/repository-delete", 400},
		{"", "/api/restic/repository-delete/status", 401},
		{"Bearer wrong", "/api/restic/repository-delete/status", 403},
		{"Bearer disposable-token", "/api/restic/repository-delete/status", 400},
		{"", "/api/restic/repository-maintenance", 401},
		{"Bearer wrong", "/api/restic/repository-maintenance", 403},
		{"Bearer disposable-token", "/api/restic/repository-maintenance", 400},
		{"", "/api/restic/repository-export", 401},
		{"Bearer wrong", "/api/restic/repository-export", 403},
		{"Bearer disposable-token", "/api/restic/repository-export", 400},
	} {
		method := "GET"
		if strings.HasPrefix(test.path, "/api/restic/repository-") || strings.HasSuffix(test.path, "/recovery") || strings.HasSuffix(test.path, "/jobs") || strings.HasSuffix(test.path, "/server-key") || strings.HasSuffix(test.path, "/repository-plan") || strings.HasSuffix(test.path, "/repository-state") {
			method = "POST"
		}
		req := httptest.NewRequest(method, test.path, nil)
		req.Header.Set("Authorization", test.token)
		w := httptest.NewRecorder()
		routes.ServeHTTP(w, req)
		if w.Code != test.status {
			t.Fatalf("expected %d got %d: %s", test.status, w.Code, w.Body.String())
		}
	}
}
