# ============================================================================
#  Bootstrap GlitchTipa jako kod — uruchamiany przez `manage.py shell < ...`.
#
#  IDEMPOTENTNY: można go puszczać po każdym `make up-errors`. Tworzy admina,
#  organizację, zespół, trzy projekty, STAŁE klucze DSN (z .env), regułę
#  alertu "nowy issue -> e-mail" i token API dla sentry-cli.
#
#  Stałe klucze sprawiają, że DSN-y w .env.example nie zależą od tego, co
#  wyklikano w UI: `make errors-reset` odtwarza identyczny stan.
#
#  Używa wewnętrznych modeli Django GlitchTipa (zweryfikowane na 6.2.6).
#  Po podbiciu wersji skrypt kończy się asercjami, więc rozjazd widać od razu
#  (RUNBOOK #069).
# ============================================================================
import os
import sys
from uuid import UUID

from django.contrib.auth import get_user_model

from apps.alerts.constants import RecipientType
from apps.alerts.models import AlertRecipient, ProjectAlert
from apps.api_tokens.models import APIToken
from apps.organizations_ext.constants import OrganizationUserRole
from apps.organizations_ext.models import Organization
from apps.projects.models import Project, ProjectKey
from apps.teams.models import Team

ORG_NAME = "marketplace"
TEAM_SLUG = "platform"
# Kolejność = ID projektów (1, 2, 3): DSN-y w compose mają te ID na sztywno.
PROJECTS = [
    ("catalog", "php-laravel", "GLITCHTIP_KEY_CATALOG"),
    ("catalog-frontend", "javascript-vue", "GLITCHTIP_KEY_FRONTEND"),
    ("search", "php-symfony", "GLITCHTIP_KEY_SEARCH"),
]
TOKEN_SCOPES = ["project:read", "project:write", "project:releases", "event:read", "org:read"]

problems = []


def need(name):
    value = os.environ.get(name, "")
    if not value:
        print(f"BŁĄD: brak zmiennej {name}")
        sys.exit(1)
    return value


User = get_user_model()
admin_email = need("GLITCHTIP_ADMIN_EMAIL")
admin_password = need("GLITCHTIP_ADMIN_PASSWORD")
api_token = need("GLITCHTIP_API_TOKEN")

# --- admin ------------------------------------------------------------------
admin = User.objects.filter(email=admin_email).first()
if admin is None:
    admin = User.objects.create_superuser(admin_email, admin_password)
else:
    admin.set_password(admin_password)
    admin.is_staff = admin.is_superuser = True
    admin.save()

# --- organizacja i zespół ---------------------------------------------------
org = Organization.objects.filter(slug=ORG_NAME).first()
if org is None:
    org = Organization.objects.create(name=ORG_NAME)
if not org.users.filter(pk=admin.pk).exists():
    org.add_user(admin, OrganizationUserRole.OWNER)
team, _ = Team.objects.get_or_create(slug=TEAM_SLUG, organization=org)
if not team.members.exists():
    team.members.add(org.organization_users.get(user=admin))

# --- projekty, klucze, alerty ----------------------------------------------
for name, platform, key_env in PROJECTS:
    wanted = UUID(need(key_env))
    project = Project.objects.filter(organization=org, name=name).first()
    if project is None:
        project = Project.objects.create(
            organization=org, name=name, platform=platform, scrub_ip_addresses=True
        )
    team.projects.add(project)

    keys = list(ProjectKey.objects.filter(project=project).order_by("id"))
    key = next((k for k in keys if k.public_key == wanted), keys[0])
    if key.public_key != wanted:
        key.public_key = wanted
    key.is_active = True
    key.save()
    ProjectKey.objects.filter(project=project).exclude(pk=key.pk).update(is_active=False)

    alert, _ = ProjectAlert.objects.get_or_create(
        project=project, name="Nowy issue", defaults={"timespan_minutes": 1, "quantity": 1}
    )
    AlertRecipient.objects.get_or_create(
        alert=alert, recipient_type=RecipientType.EMAIL, url=""
    )

# --- token API dla sentry-cli ----------------------------------------------
token = APIToken.objects.filter(token=api_token).first()
if token is None:
    token = APIToken.objects.create(user=admin, token=api_token, label="sentry-cli")
for scope in TOKEN_SCOPES:
    setattr(token.scopes, scope, True)
token.save()

# --- asercje: rozjazd ma być głośny ----------------------------------------
for expected_id, (name, _platform, key_env) in enumerate(PROJECTS, start=1):
    project = Project.objects.filter(organization=org, name=name).first()
    if project is None or project.pk != expected_id:
        problems.append(f"projekt {name}: ID {getattr(project, 'pk', None)}, oczekiwane {expected_id}")
        continue
    active = list(ProjectKey.objects.filter(project=project, is_active=True))
    if [str(k.public_key) for k in active] != [str(UUID(need(key_env)))]:
        problems.append(f"projekt {name}: aktywne klucze nie zgadzają się z {key_env}")
    if not ProjectAlert.objects.filter(project=project, alertrecipient__recipient_type="email").exists():
        problems.append(f"projekt {name}: brak reguły alertu e-mail")
if not APIToken.objects.filter(token=api_token, user=admin).exists():
    problems.append("brak tokenu API")

if problems:
    print("BŁĄD bootstrapu GlitchTipa:")
    for p in problems:
        print(f"  - {p}")
    print("Patrz docs/RUNBOOK.md #069 (make errors-reset odtwarza bazę od zera).")
    sys.exit(1)

print("OK: 3 projekty, klucze, alerty, token")
