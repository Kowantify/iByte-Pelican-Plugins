package engine

import (
	"encoding/base64"
	"errors"
	"path/filepath"
	"strings"
)

// Recovery is only exported on the daemon-authenticated server route to Panel's
// encrypted root-admin vault. Never include it in job/status/readiness/logs.
type Recovery struct {
	Generation        uint64 `json:"generation,omitempty"`
	CredentialMode    string `json:"credential_mode"`
	KeyFinalized      bool   `json:"key_finalized"`
	PreviousBase64    string `json:"previous_password_base64,omitempty"`
	Host              int    `json:"host"`
	Node              int    `json:"node"`
	Server            string `json:"server"`
	Profile           string `json:"profile"`
	Backend           string `json:"backend"`
	Repository        string `json:"repository"`
	OriginalRoot      string `json:"original_root"`
	PasswordFile      string `json:"password_file"`
	PasswordBase64    string `json:"password_base64"`
	CredentialsFile   string `json:"credentials_file,omitempty"`
	CredentialsBase64 string `json:"credentials_base64,omitempty"`
	RetentionSafe     bool   `json:"retention_safe"`
}

// A retained local repository must not overlap paths native Wings destroys.
func (r Recovery) Outside(roots ...string) bool {
	if r.Backend == "s3" {
		return true
	}
	if !filepath.IsAbs(r.Repository) {
		return false
	}
	for _, root := range roots {
		if !filepath.IsAbs(root) {
			return false
		}
		for _, pair := range [][2]string{{root, r.Repository}, {r.Repository, root}} {
			rel, err := filepath.Rel(pair[0], pair[1])
			if err != nil || rel == "." || (rel != ".." && !strings.HasPrefix(rel, ".."+string(filepath.Separator))) {
				return false
			}
		}
	}
	return true
}

func (e *Engine) Recovery(r Request) (Recovery, error) {
	var out Recovery
	repo, p, err := e.cfg.repository(r)
	if err != nil {
		return out, err
	}
	if !filepath.IsAbs(r.Root) || filepath.Base(r.Root) != r.Server {
		return out, errors.New("invalid server root")
	}
	password, err := privateFile(p.PasswordFile)
	if err != nil || len(password) == 0 {
		return out, errors.New("recovery credential unavailable")
	}
	out = Recovery{Generation: r.Generation, Host: r.Host, Node: r.Node, Server: r.Server, Profile: r.Profile, Backend: p.Backend, Repository: repo, OriginalRoot: r.Root, PasswordFile: p.PasswordFile, PasswordBase64: base64.StdEncoding.EncodeToString(password)}
	out.CredentialMode = "legacy-profile"
	if k, err := e.cfg.key(r); err == nil && k.Prepared {
		out.CredentialMode, out.KeyFinalized, out.PreviousBase64 = "server", k.Finalized, k.Previous
	}
	if !out.Outside(r.Root) {
		return Recovery{}, errors.New("repository overlaps server data")
	}
	out.RetentionSafe = true
	if p.Backend == "s3" {
		credentials, err := privateFile(p.CredentialsFile)
		if err != nil {
			return Recovery{}, errors.New("recovery storage credential unavailable")
		}
		out.CredentialsFile = p.CredentialsFile
		out.CredentialsBase64 = base64.StdEncoding.EncodeToString(credentials)
	}
	return out, nil
}
