FROM mcr.microsoft.com/dotnet/aspnet:10.0 AS runtime
WORKDIR /app
EXPOSE 8080

FROM mcr.microsoft.com/dotnet/sdk:10.0 AS build
WORKDIR /src
COPY dotnet-app/Ocwbs.Web.csproj ./
RUN dotnet restore Ocwbs.Web.csproj
COPY dotnet-app/*.cs ./
COPY dotnet-app/wwwroot ./wwwroot
COPY dotnet-app/appsettings*.json ./
COPY assets ./legacy-static/assets
COPY dist ./legacy-static/dist
COPY plugins ./legacy-static/plugins
RUN dotnet publish Ocwbs.Web.csproj -c Release -o /out --no-restore

FROM runtime AS final
WORKDIR /app
COPY --from=build /out ./
COPY --from=build /src/legacy-static ./legacy-static
RUN mkdir -p ./legacy-static/uploads
ENTRYPOINT ["dotnet", "Ocwbs.Web.dll"]
