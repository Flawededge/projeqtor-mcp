FROM maven:3.9.11-eclipse-temurin-21 AS provider-build
WORKDIR /build
COPY pom.xml ./
COPY src ./src
RUN mvn --batch-mode --no-transfer-progress -DskipTests package

FROM quay.io/keycloak/keycloak:26.7.4@sha256:82a77884f3af238beab1e7afd63b5f530e1b5c0590bd7aa60b40a40463e29b2c
COPY --from=provider-build --chown=1000:1000 \
  /build/target/projeqtor-keycloak-claude-cimd-provider-2.1.1.jar \
  /opt/keycloak/providers/projeqtor-keycloak-claude-cimd-provider.jar
RUN /opt/keycloak/bin/kc.sh build --features=cimd
